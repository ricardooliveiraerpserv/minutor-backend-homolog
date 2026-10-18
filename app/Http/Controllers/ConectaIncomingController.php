<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recepção, na ERPSERV, das interações que a CONECTA empurra de volta (resposta do agente) —
 * o outro lado do espelho (S2 de Conecta→ERPSERV). Auth por segredo compartilhado
 * (X-Migration-Secret, mesmo da ida). Marca os comentários como source='conecta_sync' p/ o
 * anti-eco (o sync outbound não os devolve). Idempotente. Ver [[project_minutor_conecta_client_migration]].
 */
class ConectaIncomingController extends Controller
{
    public function receiveTicket(Request $request): JsonResponse
    {
        $secret = (string) config('services.conecta.migration_secret', '');
        $given  = (string) $request->header('X-Migration-Secret', '');
        if ($secret === '' || ! hash_equals($secret, $given)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'ticket'                        => 'required|array',
            'ticket.source_system'          => 'required|string|max:40',
            'ticket.external_ref'           => 'required|string|max:100',
            'ticket.status_key'             => 'nullable|string|max:60',
            'ticket.last_activity_at'       => 'nullable|date',
            'comments'                      => 'nullable|array',
            'comments.*.external_action_id' => 'nullable|string|max:100',
            'comments.*.body'               => 'nullable|string',
            'comments.*.visibility'         => 'nullable|string|max:20',
            'comments.*.is_system'          => 'nullable|boolean',
            'comments.*.created_at'         => 'nullable|date',
        ]);

        $t   = $data['ticket'];
        $ss  = (string) $t['source_system'];
        $ref = (string) $t['external_ref'];

        // Resolve o ticket ESPELHO na ERPSERV: nativo vem como erpserv-<id>; Movidesk por source+ref.
        $ticket = null;
        if ($ss === 'erpserv' && preg_match('/^erpserv-(\d+)$/', $ref, $m)) {
            $ticket = HelpDeskTicket::withoutCompanyScope()->find((int) $m[1]);
        } else {
            $ticket = HelpDeskTicket::withoutCompanyScope()
                ->where('source_system', $ss)->where('external_ref', $ref)->first();
        }
        if (! $ticket) {
            return response()->json(['message' => 'Ticket espelho não encontrado na ERPSERV.', 'source_ref' => "{$ss}/{$ref}"], 404);
        }

        $created = 0;
        foreach (($data['comments'] ?? []) as $i => $c) {
            $aid = $c['external_action_id'] ?? null;
            $idemKey = 'conecta-' . $ticket->id . '-a' . ($aid ?? ('h' . substr(md5((string) ($c['body'] ?? '') . $i), 0, 12)));
            $exists = HelpDeskTicketComment::where('ticket_id', $ticket->id)
                ->where(function ($q) use ($idemKey, $aid) {
                    $q->where('idempotency_key', $idemKey);
                    if ($aid) { $q->orWhere('external_action_id', (string) $aid); }
                })->exists();
            if ($exists) { continue; }

            $comment = new HelpDeskTicketComment();
            $comment->forceFill([
                'ticket_id'          => $ticket->id,
                'author_user_id'     => null,
                'body'               => (string) ($c['body'] ?? ''),
                'visibility'         => (string) ($c['visibility'] ?? 'customer'),
                'is_system'          => (bool) ($c['is_system'] ?? false),
                'channel'            => 'conecta',
                'source'             => 'conecta_sync', // anti-eco: o sync outbound NÃO devolve isto
                'external_action_id' => $aid,
                'idempotency_key'    => $idemKey,
            ]);
            if (! empty($c['created_at'])) { $comment->created_at = $c['created_at']; }
            $comment->saveQuietly(); // sem eventos/observers
            $created++;
        }

        // Atualiza status (por key) + last_activity_at do espelho.
        if (! empty($t['status_key'])) {
            $sid = optional(HelpDeskStatus::withoutCompanyScope()->where('key', $t['status_key'])->where('company_id', $ticket->company_id)->first())->id
                ?? optional(HelpDeskStatus::withoutCompanyScope()->where('key', $t['status_key'])->first())->id;
            if ($sid) { $ticket->status_id = $sid; }
        }
        if (! empty($t['last_activity_at'])) { $ticket->last_activity_at = $t['last_activity_at']; }
        $ticket->saveQuietly();

        return response()->json([
            'success'           => true,
            'erpserv_ticket_id' => $ticket->id,
            'comments_created'  => $created,
        ], 201);
    }
}
