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
            'status'        => $t->status ? ['label' => $t->status->label, 'cor' => $t->status->color] : null,
            'criado_em'     => optional($t->created_at)->toIso8601String(),
            'atualizado_em' => optional($t->updated_at)->toIso8601String(),
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

        // Campos NOVOS (opt-in: só aparecem se o perfil habilitar).
        $extra = [];
        if ($view['service'] ?? false)       $extra['servico']             = optional($t->service)->name;
        if ($view['responsible'] ?? false)   $extra['responsavel']         = optional($t->assignee)->name;
        if ($view['category'] ?? false)      $extra['categoria']           = optional($t->category)->name;
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

    /** Comentário público — identidade do atendente NÃO é exposta (só "atendimento" × "você"). */
    public static function comment(HelpDeskTicketComment $c, int $clientUserId): array
    {
        return [
            'id'        => $c->id,
            'de'        => ($c->author_user_id !== null && (int) $c->author_user_id === $clientUserId) ? 'voce' : 'atendimento',
            'mensagem'  => $c->body,
            'criado_em' => optional($c->created_at)->toIso8601String(),
        ];
    }

    /** Anexo público — link de download via rota do Portal. */
    public static function attachment(Attachment $a, int $ticketId): array
    {
        return [
            'id'        => $a->id,
            'nome'      => $a->original_name,
            'tamanho'   => $a->human_size ?? null,
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
