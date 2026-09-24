<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Semeia os gatilhos (workflows) de e-mail do Help Desk que faltavam, cobrindo todas as situações:
 * abertura pelo portal, respostas (equipe→cliente com assinatura do consultor, cliente→responsável),
 * mudanças de status (resolvido/aguardando cliente/fechado), SLA parado, triagem (novo na fila) e
 * continuação de chamado encerrado (→ Coordenador de Sustentação) e mesclagem (→ cliente + responsável).
 *
 * TODOS entram DESATIVADOS (enabled = false): o admin abre a prévia de cada um e ativa quando aprovar.
 * Idempotente: não recria um gatilho que já exista com o mesmo nome. NÃO altera o motor de gatilhos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach ($this->triggers() as $t) {
            $exists = DB::table('helpdesk_triggers')->where('name', $t['name'])->whereNull('deleted_at')->exists();
            if ($exists) continue;
            DB::table('helpdesk_triggers')->insert([
                'name'            => $t['name'],
                'enabled'         => false,
                'event'           => $t['event'],
                'condition_logic' => 'all',
                'conditions'      => json_encode($t['conditions']),
                'actions'         => json_encode([[
                    'type'   => 'send_email',
                    'params' => [
                        'to'         => $t['to'],
                        'skip_actor' => true,
                        'layout'     => 'template',
                        'subject'               => $t['subject'],
                        'notification_title'    => $t['ntitle'] ?? null,
                        'notification_subtitle' => $t['nsub'] ?? null,
                        'message'               => $t['message'] ?? '',
                        'blocks'                => $t['blocks'] ?? [],
                    ],
                ]]),
                'run_order'  => 100,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Gatilhos ORIGINAIS (se existirem) também ganham o bloco de notificação + assunto padronizado.
        $origin = [
            'Notificar responsável na PRIMEIRA atribuição (triagem)'       => ['👤 Chamado atribuído', 'Um chamado foi encaminhado para você na triagem. Inicie o atendimento assim que possível.', '[ERPSERV] Chamado nº {ticket.number} atribuído a você'],
            'Notificar novo responsável quando o chamado é transferido'    => ['👤 Chamado transferido', 'Um chamado foi transferido para você. Verifique o histórico e continue o atendimento.', '[ERPSERV] Chamado nº {ticket.number} transferido para você'],
            'Confirmação ao cliente — e-mails recebidos e transformados em chamado' => ['📩 Chamado recebido', 'Recebemos sua solicitação com sucesso. Nossa equipe iniciará a análise em breve.', '[ERPSERV] Chamado nº {ticket.number} registrado — {ticket.subject}'],
        ];
        foreach ($origin as $name => [$nt, $ns, $subj]) {
            $row = DB::table('helpdesk_triggers')->where('name', $name)->whereNull('deleted_at')->first();
            if (!$row) continue;
            $actions = json_decode($row->actions ?? '[]', true);
            if (!is_array($actions)) continue;
            foreach ($actions as $i => $a) {
                if (($a['type'] ?? '') === 'send_email') {
                    unset($actions[$i]['params']['title']);
                    $actions[$i]['params']['notification_title']    = $nt;
                    $actions[$i]['params']['notification_subtitle'] = $ns;
                    $actions[$i]['params']['subject']               = $subj;
                    $actions[$i]['params']['layout']                = 'template';
                    $actions[$i]['params']['message']               = '';
                }
            }
            DB::table('helpdesk_triggers')->where('id', $row->id)->update(['actions' => json_encode($actions), 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $names = array_map(fn ($t) => $t['name'], $this->triggers());
        DB::table('helpdesk_triggers')->whereIn('name', $names)->delete(); // soft delete (deleted_at)
    }

    private function cond(string $field, string $op, $value = null): array
    {
        return ['group' => 'all', 'field' => $field, 'operator' => $op, 'value' => $value];
    }

    private function triggers(): array
    {
        return [
            // 1) Abertura pelo PORTAL → confirmação ao cliente
            [
                'name' => 'Confirmação ao cliente — chamado aberto pelo portal',
                'event' => 'ticket_created', 'to' => ['cliente'],
                'conditions' => [$this->cond('channel', 'eq', 'portal')],
                'ntitle'  => '📩 Chamado recebido',
                'nsub'    => 'Recebemos sua solicitação com sucesso. Nossa equipe iniciará a análise em breve.',
                'subject' => '[ERPSERV] Chamado nº {ticket.number} registrado — {ticket.subject}',
                'message' => '',
            ],
            // 2) Nova resposta da EQUIPE (pública) → cliente, com assinatura do consultor
            [
                'name' => 'Nova resposta da equipe → cliente',
                'event' => 'comment_added', 'to' => ['cliente'],
                'conditions' => [$this->cond('comment_by', 'eq', 'agent'), $this->cond('visibility', 'eq', 'customer')],
                'ntitle'  => '💬 Nova atualização',
                'nsub'    => 'Há uma nova interação registrada em seu chamado. Confira abaixo.',
                'subject' => '[ERPSERV] Chamado nº {ticket.number} — nova resposta da equipe',
                'message' => '',
                'blocks' => ['last_public', 'assignee_signature'],
            ],
            // 3) Nova resposta do CLIENTE → responsável
            [
                'name' => 'Nova resposta do cliente → responsável',
                'event' => 'comment_added', 'to' => ['responsavel'],
                'conditions' => [$this->cond('comment_by', 'eq', 'client')],
                'ntitle'  => '💬 Nova resposta do cliente',
                'nsub'    => 'O cliente respondeu ao chamado. Verifique o histórico e dê continuidade.',
                'subject' => '[ERPSERV] Chamado nº {ticket.number} — resposta do cliente',
                'message' => '',
                'blocks' => ['last_public'],
            ],
            // 4) Resolvido → cliente (aceite)
            [
                'name' => 'Chamado resolvido → cliente (aceite)',
                'event' => 'status_changed', 'to' => ['cliente'],
                'conditions' => [$this->cond('status_id', 'eq', 4)],
                'ntitle'  => '✅ Atendimento concluído',
                'nsub'    => 'Nossa equipe concluiu o atendimento e aguarda a sua confirmação abaixo.',
                'subject' => '[ERPSERV] Chamado nº {ticket.number} resolvido — confirme o encerramento',
                'message' => '',
            ],
            // 5) Aguardando cliente → cliente
            [
                'name' => 'Aguardando cliente → cliente',
                'event' => 'status_changed', 'to' => ['cliente'],
                'conditions' => [$this->cond('status_id', 'eq', 3)],
                'ntitle'  => '⏳ Aguardando seu retorno',
                'nsub'    => 'Precisamos de informações adicionais para prosseguir com o atendimento.',
                'subject' => '[ERPSERV] Chamado nº {ticket.number} aguarda seu retorno',
                'message' => '',
            ],
            // 6) Fechado → cliente
            [
                'name' => 'Chamado encerrado → cliente',
                'event' => 'status_changed', 'to' => ['cliente'],
                'conditions' => [$this->cond('status_id', 'eq', 5)],
                'ntitle'  => '📦 Chamado encerrado',
                'nsub'    => 'O chamado foi encerrado. Caso o problema persista, basta responder este e-mail.',
                'subject' => '[ERPSERV] Chamado nº {ticket.number} encerrado',
                'message' => '',
            ],
            // 7) Parado / SLA → responsável
            [
                'name' => 'Chamado parado (SLA) → responsável',
                'event' => 'idle_in_status', 'to' => ['responsavel'],
                'conditions' => [$this->cond('idle_hours', 'gte', 24)],
                'ntitle'  => '⏳ Chamado parado',
                'nsub'    => 'Este chamado está sem movimentação há mais de 24h. Retome o atendimento ou atualize o status.',
                'subject' => '[ERPSERV] Chamado nº {ticket.number} parado há mais de 24h',
                'message' => '',
                'blocks' => ['sla'],
            ],
            // 8) Novo na fila para TRIAGEM (sem responsável, não é continuação) → Coord. Sustentação
            [
                'name' => 'Novo chamado para triagem → Coord. Sustentação',
                'event' => 'ticket_created', 'to' => ['coordenador_sustentacao'],
                'conditions' => [$this->cond('has_assignee', 'is_false'), $this->cond('is_continuation', 'is_false')],
                'ntitle'  => '🗂️ Novo chamado para triagem',
                'nsub'    => 'Um novo chamado entrou na fila sem responsável. Faça a triagem e atribua o atendimento.',
                'subject' => '[ERPSERV] Chamado nº {ticket.number} para triagem',
                'message' => '',
            ],
            // 9) Continuação de chamado encerrado → Coord. Sustentação
            [
                'name' => 'Continuação de chamado encerrado → Coord. Sustentação',
                'event' => 'ticket_created', 'to' => ['coordenador_sustentacao'],
                'conditions' => [$this->cond('is_continuation', 'is_true')],
                'ntitle'  => '🔄 Continuação de chamado',
                'nsub'    => 'Um chamado encerrado recebeu uma nova solicitação e gerou este atendimento. Faça a triagem.',
                'subject' => '[ERPSERV] Chamado nº {ticket.number} — continuação de encerrado',
                'message' => '',
            ],
            // 10) Mesclagem → cliente + responsável (do destino)
            [
                'name' => 'Chamados mesclados → cliente e responsável',
                'event' => 'merged', 'to' => ['cliente', 'responsavel'],
                'conditions' => [],
                'ntitle'  => '🔔 Chamados unificados',
                'nsub'    => 'Identificamos que sua solicitação trata da mesma ocorrência de um chamado já existente. O acompanhamento passará a ser realizado pelo chamado abaixo.',
                'subject' => '[ERPSERV] Chamado nº {ticket.number} — chamados unificados',
                'message' => '',
            ],
        ];
    }
};
