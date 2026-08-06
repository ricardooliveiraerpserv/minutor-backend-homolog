<?php

namespace App\Services;

use App\Models\HelpDeskEmailAccount;
use App\Models\HelpDeskIngestedEmail;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketEvent;
use App\Models\HelpDeskTrigger;
use Illuminate\Support\Facades\Log;

/**
 * Motor de Gatilhos do Help Desk. Avalia as automações de um evento e executa as ações.
 *
 * Filosofia: as ações mutam o ticket DIRETO (não via controller) e NÃO re-disparam eventos
 * → sem recursão/loop. Tudo best-effort: falha de um gatilho/ação não derruba a operação.
 */
class HelpDeskTriggerEngine
{
    /**
     * Dispara os gatilhos de um evento.
     *
     * @param string $event   chave em HelpDeskTrigger::EVENTS
     * @param array<string,mixed> $context  ex.: ['comment_by'=>'client', 'actor_id'=>1, 'changed_field'=>'status_id']
     */
    /**
     * Versão ENFILEIRADA do dispatch: joga os gatilhos (e seus e-mails) na fila 'emails'
     * pra não sobrecarregar o Azure. Com QUEUE_CONNECTION=sync roda inline (igual dispatch).
     * @param array<string,mixed> $context
     */
    public static function queue(string $event, HelpDeskTicket $ticket, array $context = []): void
    {
        \App\Jobs\ProcessHelpDeskTriggersJob::dispatch($event, $ticket->id, $context)->onConnection(config('queue.helpdesk_email_connection'))->onQueue('emails');
    }

    public static function dispatch(string $event, HelpDeskTicket $ticket, array $context = []): void
    {
        $triggers = HelpDeskTrigger::where('enabled', true)->where('event', $event)
            ->orderBy('run_order')->orderBy('id')->get();

        foreach ($triggers as $trigger) {
            try {
                if (!self::matches($trigger, $ticket, $context)) continue;
                // idle_in_status: não refaz a ação enquanto não houver nova atividade no chamado.
                if ($event === 'idle_in_status' && self::alreadyFiredSinceActivity($trigger, $ticket)) continue;
                foreach ((array) $trigger->actions as $action) {
                    self::runAction($trigger, (array) $action, $ticket, $context);
                }
                HelpDeskTicketEvent::log($ticket->id, 'trigger', ['meta' => ['trigger_id' => $trigger->id, 'name' => $trigger->name]]);
            } catch (\Throwable $e) {
                Log::warning("HelpDesk gatilho #{$trigger->id} ({$trigger->name}) falhou: " . $e->getMessage());
            }
        }
    }

    /**
     * idle: já disparamos este gatilho para este chamado DESDE a última atividade?
     * Evita refazer/spammar enquanto o chamado segue parado. Volta a valer quando há
     * nova interação (last_activity_at é atualizado).
     */
    private static function alreadyFiredSinceActivity(HelpDeskTrigger $trigger, HelpDeskTicket $ticket): bool
    {
        $since = $ticket->last_activity_at ?? $ticket->created_at;
        return HelpDeskTicketEvent::where('ticket_id', $ticket->id)
            ->where('event_type', 'trigger')
            ->where('created_at', '>=', $since)
            ->where('meta->trigger_id', $trigger->id)
            ->exists();
    }

    /**
     * Avalia as condições. Suporta DOIS grupos por condição (campo 'group'):
     *  - grupo 'all' (Atende TODAS)  → todas devem casar
     *  - grupo 'any' (Atende QUALQUER) → pelo menos uma deve casar
     * Resultado = (TODAS ok) E (QUALQUER ok). Grupo vazio é ignorado.
     * Compat: condições antigas SEM 'group' caem no condition_logic (all|any).
     */
    private static function matches(HelpDeskTrigger $trigger, HelpDeskTicket $ticket, array $context): bool
    {
        $conds = (array) $trigger->conditions;
        if (empty($conds)) return true;

        $eval = fn ($c) => self::evalCondition((array) $c, $ticket, $context);

        // Legado: nenhuma condição tem 'group' → usa condition_logic sobre todas.
        if (!collect($conds)->contains(fn ($c) => isset($c['group']))) {
            $results = array_map($eval, $conds);
            return $trigger->condition_logic === 'any' ? in_array(true, $results, true) : !in_array(false, $results, true);
        }

        $all = array_values(array_filter($conds, fn ($c) => (($c['group'] ?? 'all') !== 'any')));
        $any = array_values(array_filter($conds, fn ($c) => (($c['group'] ?? 'all') === 'any')));

        $allOk = empty($all) || !in_array(false, array_map($eval, $all), true);
        $anyOk = empty($any) || in_array(true, array_map($eval, $any), true);

        return $allOk && $anyOk;
    }

    /** @param array{field?:string,operator?:string,value?:mixed} $c */
    private static function evalCondition(array $c, HelpDeskTicket $ticket, array $context): bool
    {
        $field = $c['field'] ?? '';
        $op    = $c['operator'] ?? 'eq';
        $value = $c['value'] ?? null;

        // Campos booleanos (SLA estourado, tem responsável, é transferência).
        $boolMap = [
            'first_response_breached' => (bool) $ticket->first_response_breached,
            'resolution_breached'     => (bool) $ticket->resolution_breached,
            'has_assignee'            => !empty($ticket->assignee_id),
            'is_reassignment'         => !empty($context['was_assigned']), // já tinha responsável antes = transferência
            'is_continuation'         => !empty($ticket->previous_ticket_id), // novo chamado gerado de um encerrado
        ];
        if (array_key_exists($field, $boolMap)) {
            return $op === 'is_false' ? !$boolMap[$field] : $boolMap[$field];
        }

        // Tag (relação muitos-p/-muitos).
        if ($field === 'has_tag') {
            $tagIds = $ticket->relationLoaded('tags') ? $ticket->tags->pluck('id') : $ticket->tags()->pluck('helpdesk_tags.id');
            $has = $tagIds->contains((int) $value);
            return in_array($op, ['neq', 'not_in'], true) ? !$has : $has;
        }

        // Valor atual do ticket p/ o campo.
        $actual = match ($field) {
            'subject'          => $ticket->subject,
            'description'      => strip_tags((string) $ticket->description),
            'channel'          => $ticket->channel,
            'requester_email'  => $ticket->requester_email,
            // "Recebido em": conta de e-mail que originou o chamado (do ledger de ingestão).
            'received_account' => HelpDeskIngestedEmail::where('ticket_id', $ticket->id)->orderBy('id')->value('email_account_id'),
            'status_id'        => $ticket->status_id,
            'priority'         => $ticket->priority,
            'level'            => $ticket->level,
            'category_id'      => $ticket->category_id,
            'service_id'       => $ticket->service_id,
            'team_id'          => $ticket->team_id,
            'assignee_id'      => $ticket->assignee_id,
            'customer_id'      => $ticket->customer_id,
            'justification_id' => $ticket->justification_id,
            'reopen_count'     => $ticket->reopen_count,
            'comment_by'       => $context['comment_by'] ?? null,
            'visibility'       => $context['visibility'] ?? null, // interação: 'customer' (pública) | 'internal'
            'idle_hours'       => $ticket->last_activity_at ? abs(now()->diffInHours($ticket->last_activity_at)) : 0,
            default            => null,
        };

        // Operadores de texto (contém / começa com).
        if (in_array($op, ['contains', 'not_contains', 'starts_with'], true)) {
            $hay = mb_strtolower((string) $actual); $needle = mb_strtolower((string) $value);
            return match ($op) {
                'contains'     => $needle !== '' && str_contains($hay, $needle),
                'not_contains' => $needle === '' || !str_contains($hay, $needle),
                'starts_with'  => str_starts_with($hay, $needle),
            };
        }

        // Comparação numérica (tempo parado, reaberturas...).
        if (in_array($op, ['gte', 'lte'], true)) {
            return $op === 'gte' ? (float) $actual >= (float) $value : (float) $actual <= (float) $value;
        }

        // Igualdade / pertinência.
        $list = is_array($value) ? array_map('strval', $value) : [strval($value)];
        $a    = strval($actual);

        return match ($op) {
            'neq'    => $a !== strval($value),
            'in'     => in_array($a, $list, true),
            'not_in' => !in_array($a, $list, true),
            default  => $a === strval($value), // eq
        };
    }

    /** @param array{type?:string,params?:array} $action */
    private static function runAction(HelpDeskTrigger $trigger, array $action, HelpDeskTicket $ticket, array $context): void
    {
        $type   = $action['type'] ?? '';
        $params = (array) ($action['params'] ?? []);

        match ($type) {
            'send_email'    => self::actSendEmail($params, $ticket, $context, $trigger->name),
            'change_status' => self::actChangeStatus($params, $ticket),
            'set_field'     => self::actSetField($params, $ticket),
            'add_tag'       => $ticket->tags()->syncWithoutDetaching([(int) ($params['tag_id'] ?? 0)]),
            'remove_tag'    => $ticket->tags()->detach((int) ($params['tag_id'] ?? 0)),
            'assign'        => self::actAssign($params, $ticket),
            default         => null,
        };
    }

    private static function actChangeStatus(array $params, HelpDeskTicket $ticket): void
    {
        $statusId = (int) ($params['status_id'] ?? 0);
        if (!$statusId || $ticket->status_id === $statusId) return;
        $status = HelpDeskStatus::find($statusId);
        if (!$status) return;

        $from = $ticket->status_id;
        $ticket->status_id = $statusId;
        if ($status->is_resolved && !$ticket->resolved_at) $ticket->resolved_at = now();
        if ($status->is_terminal && !$ticket->closed_at) $ticket->closed_at = now();
        $ticket->last_activity_at = now();
        $ticket->save();
        HelpDeskTicketEvent::log($ticket->id, 'status', ['from_value' => (string) $from, 'to_value' => (string) $statusId, 'meta' => ['via' => 'trigger']]);
    }

    private static function actSetField(array $params, HelpDeskTicket $ticket): void
    {
        $field = $params['field'] ?? '';
        if (!in_array($field, ['category_id', 'service_id', 'priority', 'level', 'team_id'], true)) return;
        $ticket->{$field} = $params['value'] ?? null;
        $ticket->save();
        HelpDeskTicketEvent::log($ticket->id, 'field', ['meta' => ['field' => $field, 'via' => 'trigger']]);
    }

    private static function actAssign(array $params, HelpDeskTicket $ticket): void
    {
        if (array_key_exists('assignee_id', $params)) $ticket->assignee_id = $params['assignee_id'] ?: null;
        if (array_key_exists('team_id', $params))     $ticket->team_id = $params['team_id'] ?: null;
        $ticket->save();
        HelpDeskTicketEvent::log($ticket->id, 'assign', ['meta' => ['via' => 'trigger']]);
    }

    /** Registra no histórico do chamado que a notificação foi enviada (p/ quem + ok/erro). */
    private static function logEmailSent(HelpDeskTicket $ticket, array $to, array $params, bool $ok, ?string $err, ?string $triggerName): void
    {
        $toList = (array) ($params['to'] ?? []);
        $publico = (in_array('cliente', $toList, true) || in_array('requester', $toList, true)) ? 'cliente'
            : (in_array('responsavel', $toList, true) ? 'responsavel' : 'equipe');
        HelpDeskTicketEvent::log($ticket->id, 'email_sent', [
            'to_value' => implode(', ', $to),
            'meta' => ['to' => $to, 'ok' => $ok, 'error' => $err, 'publico' => $publico, 'regra' => $triggerName, 'via' => 'trigger'],
        ]);
    }

    private static function actSendEmail(array $params, HelpDeskTicket $ticket, array $context, ?string $triggerName = null): void
    {
        if (!GraphMailSender::enabled()) return;

        $to = [];
        foreach ((array) ($params['to'] ?? []) as $target) {
            foreach (self::resolveRecipients((string) $target, $ticket) as $addr) {
                if ($addr) $to[] = $addr;
            }
        }
        // "não enviar p/ quem disparou"
        if (!empty($params['skip_actor']) && !empty($context['actor_email'])) {
            $to = array_values(array_filter($to, fn ($e) => strcasecmp($e, $context['actor_email']) !== 0));
        }
        $to = array_values(array_unique($to));
        if (empty($to)) return;

        $from = self::senderAccount($params['sender_account_id'] ?? null, $ticket);
        if (!$from) return;

        // Assunto SEMPRE o do fio do chamado (Re: [nº] assunto). O Exchange agrupa a conversa pelo
        // ConversationTopic (o assunto): um assunto customizado ("Chamado nº X encerrado") criava uma
        // conversa NOVA no Apple Mail, fora do fio. O aviso ("encerrado" etc.) já está no CORPO.
        $subject = \App\Services\HelpDeskReplyMailer::subjectFor($ticket);

        // Modo TEMPLATE (novo): admin informa mensagem + blocos → composer monta o layout
        // institucional (logo/cabeçalho/assinatura/rodapé já inclusos → sem o footer auto).
        // Modo RAW (legado): body HTML/texto renderizado direto + footer padrão. Compat preservada.
        $layout = $params['layout'] ?? (array_key_exists('message', $params) ? 'template' : 'raw');
        if ($layout === 'template') {
            // Público da saudação: cliente (nome do solicitante) · responsável (nome do agente) ·
            // interno (coordenador/equipe → saudação genérica "Olá,", não parece ser para o cliente).
            $toList = (array) ($params['to'] ?? []);
            $audience = (in_array('cliente', $toList, true) || in_array('requester', $toList, true)) ? 'cliente'
                : (in_array('responsavel', $toList, true) ? 'responsavel' : 'interno');
            $html = HelpDeskMailComposer::compose(
                (string) ($params['message'] ?? ''), (array) ($params['blocks'] ?? []), $ticket, null, $audience,
                isset($params['notification_title']) ? (string) $params['notification_title'] : null,
                isset($params['notification_subtitle']) ? (string) $params['notification_subtitle'] : null,
            );
            // Imagens/assinatura do chamado (data:) → cid inline, pra renderizarem no e-mail.
            [$html, $imgAtts] = HelpDeskMailComposer::inlineImages($html);
            $inline = array_merge(HelpDeskMailComposer::inlineAssets(), $imgAtts);
            [$ok, $err] = GraphMailSender::sendAs((string) $from->email, $to, [], $subject, $html, [], $inline, false, [], $ticket->graph_thread_msg_id);
        } else {
            $body = nl2br(self::render((string) ($params['body'] ?? ''), $ticket));
            $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111827;line-height:1.5">' . $body . '</div>';
            [$ok, $err] = GraphMailSender::sendAs((string) $from->email, $to, [], $subject, $html, [], [], true, [], $ticket->graph_thread_msg_id);
        }
        self::logEmailSent($ticket, $to, $params, (bool) ($ok ?? false), $err ?? null, $triggerName);
    }

    /** Resolve um destinatário simbólico em e-mail. Aceita também e-mail fixo (contém @). */
    /** Resolve um alvo para 1..N e-mails (alvos de GRUPO, ex.: coordenadores, retornam vários). */
    private static function resolveRecipients(string $target, HelpDeskTicket $ticket): array
    {
        if ($target === 'coordenador_sustentacao') {
            return \App\Models\User::where('type', 'coordenador')->where('coordinator_type', 'sustentacao')
                ->pluck('email')->filter()->values()->all();
        }
        $one = self::resolveRecipient($target, $ticket);
        return $one ? [$one] : [];
    }

    private static function resolveRecipient(string $target, HelpDeskTicket $ticket): ?string
    {
        if (str_contains($target, '@')) return $target; // e-mail fixo

        return match ($target) {
            'responsavel' => optional($ticket->assignee)->email,
            'cliente'     => self::clientEmail($ticket),
            'requester'   => optional($ticket->requester)->email,
            default       => null,
        };
    }

    private static function clientEmail(HelpDeskTicket $ticket): ?string
    {
        if ($email = optional($ticket->contact)->email) return $email;
        $led = HelpDeskIngestedEmail::where('ticket_id', $ticket->id)->whereNotNull('from_email')->orderBy('id')->first();
        return $led?->from_email ?? optional($ticket->requester)->email;
    }

    private static function senderAccount(?int $id, HelpDeskTicket $ticket): ?HelpDeskEmailAccount
    {
        $base = HelpDeskEmailAccount::where('provider', 'microsoft365')->where('enabled', true);
        if ($id) return (clone $base)->find($id) ?? $base->orderBy('id')->first();
        // "Conta que recebeu o e-mail": preferimos a conta de onde o chamado entrou (ledger).
        $recvId = HelpDeskIngestedEmail::where('ticket_id', $ticket->id)->orderBy('id')->value('email_account_id');
        if ($recvId && ($r = (clone $base)->find($recvId))) return $r;
        if ($ticket->team_id && ($m = (clone $base)->where('default_team_id', $ticket->team_id)->first())) return $m;
        return $base->orderBy('id')->first();
    }

    /** Substitui placeholders {ticket.x} / {tenant.name} no texto (info automática nos e-mails). */
    public static function render(string $text, HelpDeskTicket $ticket): string
    {
        $frontend  = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $clientName = optional($ticket->contact)->name ?? optional($ticket->requester)->name ?? optional($ticket->customer)->name;
        $created   = $ticket->created_at ? $ticket->created_at->format('d/m/Y H:i') : '';

        $map = [
            '{ticket.id}'                      => (string) $ticket->id,
            '{ticket.number}'                  => (string) $ticket->ticket_number,
            '{ticket.protocol}'                => (string) $ticket->ticket_number,
            '{ticket.subject}'                 => (string) $ticket->subject,
            '{ticket.description}'             => self::plainText((string) $ticket->description),
            '{ticket.status}'                  => (string) optional($ticket->status)->label,
            '{ticket.priority}'                => (string) $ticket->priority,
            '{ticket.category}'                => (string) optional($ticket->category)->name,
            '{ticket.service}'                 => (string) optional($ticket->service)->name,
            '{ticket.team}'                    => (string) optional($ticket->team)->name,
            '{ticket.customer}'                => (string) optional($ticket->customer)->name,
            '{ticket.requester}'               => (string) ($clientName),
            '{ticket.client.name}'             => (string) ($clientName),
            '{ticket.assignee}'                => (string) optional($ticket->assignee)->name,
            '{ticket.url}'                     => $frontend . '/help-desk/tickets/' . $ticket->id,
            '{ticket.created_at}'              => $created,
            '{ticket.firstaction.date}'        => $created,
            '{ticket.summary.public.actions}'  => self::publicActionsSummary($ticket),
            '{ticket.firstaction.attachments}' => self::attachmentsList($ticket),
            '{tenant.name}'                    => (string) config('app.name', 'Minutor'),
        ];
        return strtr($text, $map);
    }

    /** Resumo das interações públicas (texto), p/ o placeholder {ticket.summary.public.actions}. */
    private static function publicActionsSummary(HelpDeskTicket $ticket): string
    {
        $rows = $ticket->comments()->where('visibility', 'customer')->orderBy('created_at')->get(['body', 'created_at']);
        if ($rows->isEmpty()) return self::plainText((string) $ticket->description);
        return $rows->map(function ($c) {
            $when = $c->created_at ? $c->created_at->format('d/m/Y H:i') . ' — ' : '';
            return '• ' . $when . self::plainText((string) $c->body);
        })->implode("\n");
    }

    /** HTML → texto limpo: <br>/<p>/<div> viram quebra, decodifica entidades, normaliza espaços. */
    private static function plainText(string $html): string
    {
        $html = preg_replace('/<\s*(br|\/p|\/div|\/tr|\/li)\s*\/?>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;     // colapsa espaços
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;  // limita linhas em branco
        return trim($text);
    }

    /** Nomes dos anexos do chamado, p/ {ticket.firstaction.attachments}. */
    private static function attachmentsList(HelpDeskTicket $ticket): string
    {
        $names = \App\Models\Attachment::forEntity('HELPDESK_TICKET', $ticket->id)->whereNull('deleted_at')->pluck('original_name');
        return $names->isEmpty() ? '—' : $names->implode(', ');
    }
}
