<?php

namespace App\Http\Controllers;

use App\Models\SourceSemanticCampaign;
use App\Models\SourceSemanticCampaignEvent;
use App\SourceCode\Campaign\CampaignBuilder;
use App\SourceCode\Campaign\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Campanha de Documentação Semântica Inicial — Admin apenas (permission:source_docs.semantic_campaign).
 * Interna ERPSERV; cliente externo nunca acessa. NÃO é o fluxo de GMUD.
 */
class SourceDocCampaignController extends Controller
{
    public function __construct(private CampaignService $svc, private CampaignBuilder $builder)
    {
    }

    /** POST /source-docs/campaigns — cria draft e MONTA os itens (blob-oriented). Opcional: source_doc_ids (piloto). */
    public function store(Request $request): JsonResponse
    {
        $c = SourceSemanticCampaign::create([
            'name'          => (string) ($request->input('name') ?: 'Campanha ' . now()->format('Y-m-d H:i')),
            'status'        => 'estimating',
            'budget_usd'    => (float) ($request->input('budget_usd') ?: 300),
            'safety_margin' => (float) ($request->input('safety_margin') ?: 0.08),
            'max_concurrent'=> (int) ($request->input('max_concurrent') ?: 1), // 1 worker source-doc no homolog
            'batch_size'    => (int) ($request->input('batch_size') ?: 50),
            'created_by'    => $request->user()?->id,
        ]);
        SourceSemanticCampaignEvent::create(['campaign_id' => $c->id, 'actor_user_id' => $request->user()?->id, 'event' => 'created', 'created_at' => now()]);

        $ids = $request->input('source_doc_ids');
        $ids = is_array($ids) ? array_map('intval', $ids) : null;
        $stats = $this->builder->build($c, $ids);

        return response()->json(['data' => $c->refresh(), 'build' => $stats], 201);
    }

    /** GET /source-docs/campaigns — lista. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => SourceSemanticCampaign::orderByDesc('id')->limit(50)->get()]);
    }

    /** GET /source-docs/campaigns/{id} — status + progresso + últimos eventos. */
    public function show(int $id): JsonResponse
    {
        $c = SourceSemanticCampaign::find($id);
        if (! $c) {
            return response()->json(['message' => 'Campanha não encontrada.'], 404);
        }
        $processed = (int) $c->processed;
        $total = (int) $c->total_candidates;
        $reuseSaving = round(((int) $c->reused) * (float) config('services.source_doc_ai.est_medio_usd', 0.20), 2);
        return response()->json(['data' => [
            'campaign'  => $c,
            'progress'  => $total > 0 ? round(100 * $processed / $total, 1) : 0,
            'dedup_saving_usd_est' => $reuseSaving,
            'events'    => SourceSemanticCampaignEvent::where('campaign_id', $id)->orderByDesc('id')->limit(20)->get(),
        ]]);
    }

    public function start(Request $r, int $id): JsonResponse   { return $this->act($r, $id, 'start'); }
    public function pause(Request $r, int $id): JsonResponse   { return $this->act($r, $id, 'pause'); }
    public function resume(Request $r, int $id): JsonResponse  { return $this->act($r, $id, 'resume'); }
    public function cancel(Request $r, int $id): JsonResponse  { return $this->act($r, $id, 'cancel'); }

    private function act(Request $r, int $id, string $action): JsonResponse
    {
        $c = SourceSemanticCampaign::find($id);
        if (! $c) {
            return response()->json(['message' => 'Campanha não encontrada.'], 404);
        }
        $uid = $r->user()?->id;
        match ($action) {
            'start'  => $this->svc->start($c, $uid),
            'pause'  => $this->svc->pause($c, $uid, 'manual'),
            'resume' => $this->svc->resume($c, $uid),
            'cancel' => $this->svc->cancel($c, $uid),
        };
        return response()->json(['data' => $c->refresh()]);
    }
}
