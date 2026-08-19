<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Presença + detecção de mudança de um chamado (Help Desk).
 * Um único endpoint (heartbeat) por poll (~10s) que:
 *  - marca o usuário logado como VENDO o chamado agora (upsert last_seen_at);
 *  - devolve os OUTROS viewers ativos (últimos 30s) com nome + tipo (p/ o olho);
 *  - devolve um change_key p/ o FE saber se houve alteração/interação (botão Atualizar).
 * Vale p/ agente e p/ cliente (portal) — mesma tabela, mesmo user_id.
 */
class HelpDeskPresenceController extends Controller
{
    private const ACTIVE_SECONDS = 30;

    public function heartbeat(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        $user = $request->user();
        $now  = now();

        DB::table('helpdesk_ticket_views')->updateOrInsert(
            ['ticket_id' => $ticket->id, 'user_id' => $user->id],
            ['last_seen_at' => $now, 'updated_at' => $now, 'created_at' => $now],
        );

        $since = $now->copy()->subSeconds(self::ACTIVE_SECONDS);
        $viewers = DB::table('helpdesk_ticket_views as v')
            ->join('users as u', 'u.id', '=', 'v.user_id')
            ->where('v.ticket_id', $ticket->id)
            ->where('v.last_seen_at', '>=', $since)
            ->where('v.user_id', '!=', $user->id)
            ->orderBy('u.name')
            ->get(['u.id', 'u.name', 'u.type'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'type' => $r->type])
            ->values();

        return response()->json([
            'viewers'    => $viewers,
            'change_key' => $this->changeKey($ticket),
        ]);
    }

    /** Versão do portal do cliente: só marca presença em chamado do próprio customer. */
    public function portalHeartbeat(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        $user = $request->user();
        $cid  = $user->customer_id ?? null;
        if ($cid && (int) $ticket->customer_id !== (int) $cid) {
            abort(403);
        }
        return $this->heartbeat($request, $ticket);
    }

    /** Carimbo de mudança: updated_at do ticket + total e último comentário (interações). */
    private function changeKey(HelpDeskTicket $ticket): string
    {
        $agg = DB::table('helpdesk_ticket_comments')
            ->where('ticket_id', $ticket->id)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as c, COALESCE(MAX(id), 0) as m')
            ->first();

        return sprintf('%s|%d|%d', (string) $ticket->updated_at, (int) ($agg->c ?? 0), (int) ($agg->m ?? 0));
    }
}
