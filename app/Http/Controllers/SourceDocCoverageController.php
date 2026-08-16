<?php

namespace App\Http\Controllers;

use App\Jobs\InventorySourceRepoJob;
use App\Models\ClientSourceRepo;
use App\Models\SourceRepoCoverage;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Central de Fontes — C3.5. Cobertura do acervo (dashboard) + disparo do inventário.
 * Leitura sob source_docs.view; disparo sob source_docs.inventory (estrito).
 * C4a: cobertura e disparo escopados por cliente (deny-by-default).
 */
class SourceDocCoverageController extends Controller
{
    public function __construct(private SourceDocCustomerScope $scope)
    {
    }

    /** GET /source-docs/coverage — snapshot por repo + totais. */
    public function coverage(Request $request): JsonResponse
    {
        $query = SourceRepoCoverage::query()
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', (int) $request->query('customer_id')))
            ->with('sourceRepo:id,customer_id,owner,repository,branch,descricao')
            ->orderBy('owner')->orderBy('repository');

        // C4a: cobertura só dos clientes no escopo (os totais derivam destas linhas → também escopados).
        $this->scope->applyScope($query, $request->user(), 'source_repo_coverage.customer_id');

        $rows = $query->get();

        $sum = fn (string $c) => (int) $rows->sum($c);
        $totals = [
            'github_files'   => $sum('github_files'),
            'eligible'       => $sum('eligible_source_files'),
            'cataloged'      => $sum('cataloged'),
            'deterministic'  => $sum('deterministic'),
            'semantic'       => $sum('semantic'),
            'indexed'        => $sum('indexed'),
            'changed'        => $sum('changed_files'),        // documentação desatualizada
            'uncataloged'    => max(0, $sum('eligible_source_files') - $sum('cataloged')), // NÃO catalogados
            'index_pending'  => max(0, $sum('cataloged') - $sum('indexed')),               // índice stale
            'last_synced_at' => optional($rows->max('last_synced_at'))?->toIso8601String(),
        ];

        return response()->json(['data' => $rows->map(fn ($c) => [
            'source_repo_id' => $c->source_repo_id,
            'repo' => "{$c->owner}/{$c->repository}", 'branch' => $c->branch,
            'customer_id' => $c->customer_id,
            'scan_status' => $c->scan_status, 'last_error' => $c->last_error,
            'scan_started_at' => $c->scan_started_at, 'scan_finished_at' => $c->scan_finished_at,
            'last_synced_at' => $c->last_synced_at,
            'github_files' => $c->github_files, 'eligible' => $c->eligible_source_files,
            'new' => $c->new_files, 'unchanged' => $c->unchanged_files, 'changed' => $c->changed_files, 'ignored' => $c->ignored_files,
            'cataloged' => $c->cataloged, 'deterministic' => $c->deterministic, 'semantic' => $c->semantic, 'indexed' => $c->indexed,
        ])->values(), 'totals' => $totals]);
    }

    /** POST /source-docs/inventory — dispara a varredura (async na fila source-doc). */
    public function inventory(Request $request): JsonResponse
    {
        $targeted = $request->filled('customer_id') || $request->filled('source_repo_id');

        $query = ClientSourceRepo::query()->where('active', true)
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', (int) $request->input('customer_id')))
            ->when($request->filled('source_repo_id'), fn ($q) => $q->whereKey((int) $request->input('source_repo_id')));

        // C4a: disparo escopado — usuário não-global só enfileira inventário dos próprios clientes.
        $this->scope->applyScope($query, $request->user(), 'client_source_repos.customer_id');

        $repos = $query->get();

        // Auditoria de NEGATIVA (ação sensível): alvo explícito fora do escopo → nada enfileirado.
        if ($targeted && $repos->isEmpty() && ! $this->scope->isGlobal($request->user())) {
            Log::channel(config('logging.default'))->warning('source_docs.inventory negado por escopo', [
                'actor_user_id'        => $request->user()?->id,
                'requested_customer_id'=> $request->filled('customer_id') ? (int) $request->input('customer_id') : null,
                'requested_repo_id'    => $request->filled('source_repo_id') ? (int) $request->input('source_repo_id') : null,
            ]);
        }

        $batch = (int) ($request->input('batch') ?: 0);
        foreach ($repos as $repo) {
            InventorySourceRepoJob::dispatch($repo->id, $batch);
        }

        return response()->json(['data' => ['queued_repos' => $repos->count()]], 202);
    }
}
