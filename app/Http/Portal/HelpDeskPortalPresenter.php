<?php

namespace App\Http\Portal;

use App\Models\Attachment;
use App\Models\HelpDeskKbArticle;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;

/**
 * DTO/Presenter do Portal do Cliente (C2). O Portal NUNCA recebe a entidade completa
 * (`toArray()`). Aqui montamos um payload curado, expondo só o necessário ao cliente e
 * jamais campos internos: assignee, team, sla interno, flags de violação, source_system,
 * external_ref, created_by, IDs de FK internos, etc.
 */
class HelpDeskPortalPresenter
{
    /** Item de chamado (lista e base do detalhe). */
    public static function ticket(HelpDeskTicket $t, array $clientSla): array
    {
        return [
            'id'            => $t->id,               // identificador do recurso (chamado do próprio cliente)
            'numero'        => $t->ticket_number,
            'assunto'       => $t->subject,
            'prioridade'    => $t->priority,
            'status'        => $t->status ? ['key' => $t->status->key, 'label' => $t->status->label, 'cor' => $t->status->color, 'is_resolved' => (bool) $t->status->is_resolved, 'is_terminal' => (bool) $t->status->is_terminal] : null,
            'solicitante'   => $t->requester_name ?: optional($t->contact)->name,
            'agente'        => optional($t->assignee)->name,
            'criado_em'     => optional($t->created_at)->toIso8601String(),
            'atualizado_em' => optional($t->updated_at)->toIso8601String(),
            'agendamento'   => optional($t->scheduled_until)->toIso8601String(), // p/ coluna "Agendados" e badge no card
            'agendamento_dia_inteiro' => (bool) $t->scheduled_all_day,
            'sla'           => $clientSla, // resumo voltado ao cliente (sem flags internas)
        ];
    }

    /**
     * Detalhe do chamado: + descrição, comentários públicos e anexos públicos.
     * `$view` = perfil de acesso do cliente (o que ele pode VER no ticket); ausente = visível.
     */
    public static function ticketDetail(HelpDeskTicket $t, array $clientSla, iterable $comments, iterable $attachments, int $clientUserId, array $view = [], float $agentHours = 0): array
    {
        $base = self::ticket($t, $clientSla);
        // Gating de campos LEGADOS (default visível) por perfil de acesso (view_in.*).
        if (($view['urgency'] ?? true) === false) unset($base['prioridade']);
        if (($view['status'] ?? true) === false)  unset($base['status']);
        if (($view['sla_due'] ?? true) === false)  $base['sla'] = null;
        if (($view['subject'] ?? true) === false)  unset($base['assunto']);

        // Detalhes do CHAMADO visíveis ao cliente (dados do próprio chamado). Perfil de acesso
        // pode ocultar campos específicos com a flag = false.
        $extra = [];
        if (($view['customer'] ?? true) !== false)    $extra['cliente']     = optional($t->customer)->name;
        if (($view['requester'] ?? true) !== false)   $extra['solicitante'] = $t->requester_name ?: optional($t->contact)->name ?: optional($t->requester)->name;
        if (($view['agent'] ?? true) !== false)       $extra['agente']      = optional($t->assignee)->name;
        if (($view['team'] ?? true) !== false)        $extra['equipe']      = optional($t->team)->name;
        if (($view['category'] ?? true) !== false)    $extra['categoria']   = optional($t->category)->name;
        if (($view['service'] ?? true) !== false)     $extra['servico']     = optional($t->service)->name;
        if (($view['level'] ?? true) !== false)       $extra['nivel']       = $t->level;
        if (($view['reopens'] ?? true) !== false)     $extra['reaberturas'] = (int) $t->reopen_count;
        if (($view['cc'] ?? true) !== false)          $extra['cc']          = $t->cc_emails ?? [];

        // Continuação: menção ao chamado ANTERIOR (encerrado) do qual este é continuidade. Só expõe
        // se for do mesmo cliente (o cliente pode abrir o histórico dele no próprio portal).
        if ($t->previous_ticket_id && $t->relationLoaded('previousTicket') && $t->previousTicket
            && (int) $t->previousTicket->customer_id === (int) $t->customer_id) {
            $extra['chamado_anterior'] = ['id' => $t->previousTicket->id, 'numero' => $t->previousTicket->ticket_number];
        }
        // Inverso: se ESTE chamado (encerrado) teve continuidade, aponta pro chamado ATUAL (o mais recente).
        if ($t->relationLoaded('continuations') && $t->continuations->isNotEmpty()) {
            $cur = $t->continuations->sortByDesc('id')->first();
            if ($cur && (int) $cur->customer_id === (int) $t->customer_id) {
                $extra['chamado_atual'] = ['id' => $cur->id, 'numero' => $cur->ticket_number];
            }
        }

        // Extras ainda opt-in (mais internos): só aparecem se o perfil habilitar.
        if ($view['justification'] ?? false) $extra['justificativa']       = optional($t->justification)->name;
        if ($view['agent_times'] ?? false)   $extra['horas_apontadas']     = $agentHours;
        if ($view['tags'] ?? false)          $extra['tags']                = $t->relationLoaded('tags') ? $t->tags->pluck('name')->values() : [];
        if ($view['sla_first'] ?? false)     $extra['sla_primeira_resposta'] = optional($t->first_response_due_at)->toIso8601String();

        return array_merge($base, $extra, [
            'descricao'   => $t->description,
            'comentarios' => collect($comments)->map(fn (HelpDeskTicketComment $c) => self::comment($c, $clientUserId))->values(),
            'anexos'      => collect($attachments)->map(fn (Attachment $a) => self::attachment($a, $t->id))->values(),
        ]);
    }

    /** Comentário público — identidade do atendente NÃO é exposta (só "atendimento" × "você").
     *  Inclui os anexos DA INTERAÇÃO (visíveis ao cliente). */
    public static function comment(HelpDeskTicketComment $c, int $clientUserId): array
    {
        $anexos = Attachment::where('entity_type', 'HELPDESK_TICKET_COMMENT')
            ->where('entity_id', $c->id)->where('visibility', 'customer')->whereNull('deleted_at')
            ->orderBy('id')->get()
            ->map(fn (Attachment $a) => [
                'id'       => $a->id,
                'nome'     => $a->original_name,
                'tamanho'  => $a->human_size ?? null,
                'is_image' => $a->category === 'image',
                'download' => "/api/v1/help-desk/portal/tickets/{$c->ticket_id}/comments/{$c->id}/attachments/{$a->id}/download",
            ])->values();
        $ehVoce = $c->author_user_id !== null && (int) $c->author_user_id === $clientUserId;
        return [
            'id'        => $c->id,
            'de'        => $ehVoce ? 'voce' : 'atendimento',
            'autor'     => $ehVoce ? 'Você' : (optional($c->author)->name ?: 'Atendimento'),
            'mensagem'  => $c->body,
            'criado_em' => optional($c->created_at)->toIso8601String(),
            'anexos'    => $anexos,
        ];
    }

    /** Anexo público — link de download via rota do Portal. */
    public static function attachment(Attachment $a, int $ticketId): array
    {
        return [
            'id'        => $a->id,
            'nome'      => $a->original_name,
            'tamanho'   => $a->human_size ?? null,
            'is_image'  => $a->category === 'image',
            'criado_em' => optional($a->created_at)->toIso8601String(),
            'download'  => "/api/v1/help-desk/portal/tickets/{$ticketId}/attachments/{$a->id}/download",
        ];
    }

    /** Artigo da Base de Conhecimento (lista ou completo). */
    public static function kbArticle(HelpDeskKbArticle $a, bool $full = false): array
    {
        $out = [
            'id'     => $a->id,
            'titulo' => $a->title,
            'resumo' => $a->excerpt,
        ];
        if ($full) {
            $out['conteudo']  = $a->body;
            $out['categoria'] = optional($a->category)->name;
        }
        return $out;
    }
}
