<?php

namespace App\Http\Controllers;

use App\Exceptions\HelpDeskMergeException;
use App\Models\HelpDeskTicket;
use App\Services\HelpDeskAccessPolicy;
use App\Services\HelpDeskMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mesclagem/desmesclagem de chamados do Help Desk. */
class HelpDeskMergeController extends Controller
{
    public function __construct(private HelpDeskMergeService $svc, private HelpDeskAccessPolicy $access)
    {
    }

    /** POST /help-desk/tickets/merge — mescla source_ids[] no target_id (com opções). */
    public function merge(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($this->access->canMerge($u), 403, 'Seu perfil não permite mesclar chamados.');

        $v = $request->validate([
            'target_id'       => 'required|integer',
            'source_ids'      => 'required|array|min:1',
            'source_ids.*'    => 'integer',
            'keep_requesters' => 'nullable|boolean',
            'keep_cc'         => 'nullable|boolean',
            'keep_tags'       => 'nullable|boolean',
        ]);

        $target  = HelpDeskTicket::findOrFail($v['target_id']);
        $sources = HelpDeskTicket::whereIn('id', $v['source_ids'])->get();
        abort_if($sources->isEmpty(), 422, 'Nenhum chamado de origem válido.');

        // Só mescla o que o usuário PODE ver.
        foreach ($sources->push($target) as $t) {
            abort_unless($this->access->canSee($u, $t), 403, "Sem acesso ao chamado {$t->ticket_number}.");
        }

        try {
            $result = $this->svc->merge($target, $sources->reject(fn ($t) => $t->id === $target->id)->values()->all(), [
                'keep_requesters' => (bool) ($v['keep_requesters'] ?? false),
                'keep_cc'         => (bool) ($v['keep_cc'] ?? false),
                'keep_tags'       => (bool) ($v['keep_tags'] ?? false),
            ], $u);
        } catch (HelpDeskMergeException $e) {
            return response()->json(['code' => 'MERGE_BLOCKED', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['target' => $result->only(['id', 'ticket_number']), 'merged' => $this->mergedPayload($result)]]);
    }

    /** POST /help-desk/tickets/{target}/unmerge/{source} — desmescla um chamado específico. */
    public function unmerge(Request $request, HelpDeskTicket $target, HelpDeskTicket $source): JsonResponse
    {
        $u = $request->user();
        abort_unless($this->access->canMerge($u), 403, 'Seu perfil não permite desmesclar chamados.');
        abort_unless($this->access->canSee($u, $target), 403, 'Sem acesso ao chamado de destino.');

        try {
            $restored = $this->svc->unmerge($target, $source, $u);
        } catch (HelpDeskMergeException $e) {
            return response()->json(['code' => 'UNMERGE_BLOCKED', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [
            'restored' => $restored->only(['id', 'ticket_number']),
            'merged'   => $this->mergedPayload($target->fresh()),
        ]]);
    }

    /** GET /help-desk/tickets/{target}/merged — lista os chamados mesclados NESTE (seção "Tickets Mesclados"). */
    public function mergedList(Request $request, HelpDeskTicket $target): JsonResponse
    {
        abort_unless($this->access->canSee($request->user(), $target), 403);
        return response()->json(['data' => $this->mergedPayload($target)]);
    }

    /** @return array<int,array<string,mixed>> */
    private function mergedPayload(HelpDeskTicket $target): array
    {
        return $target->mergedTickets()
            ->with(['status:id,label,color', 'customer:id,name'])
            ->withCount('comments')
            ->orderBy('id')->get()
            ->map(fn (HelpDeskTicket $t) => [
                'id'            => $t->id,
                'ticket_number' => $t->ticket_number,
                'subject'       => $t->subject,
                'customer'      => optional($t->customer)->name,
                'status'        => optional($t->status)->label,
                'comments'      => $t->comments_count,
            ])->values()->all();
    }
}
