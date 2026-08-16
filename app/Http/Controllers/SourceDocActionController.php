<?php

namespace App\Http\Controllers;

use App\Jobs\ReprocessSourceDocJob;
use App\Models\SourceDoc;
use App\Models\SourceDocActionLog;
use App\Models\SourceDocVersion;
use App\SourceCode\Analyzer\SourceDiff;
use App\SourceCode\GithubAppAuth;
use App\SourceCode\SourceDocCustomerScope;
use App\SourceCode\SourceDocRenderer;
use App\SourceCode\SourceDocStatusResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Central de Fontes — C3. Ações operacionais (primeiras rotas de ESCRITA). Cada ação tem
 * permissão própria (middleware), auditoria SANITIZADA e — no reprocess — idempotência +
 * anti-concorrência. Invoca o motor v2.1 (congelado); respeita a imutabilidade de versão concluída.
 */
class SourceDocActionController extends Controller
{
    public function __construct(
        private SourceDocStatusResolver $resolver,
        private GithubAppAuth $auth,
        private SourceDocCustomerScope $scope,
    ) {
    }

    /** POST /source-docs/{id}/validate — força o resolver, atualiza situação. Sync. Não muda versão. */
    public function validate(int $sourceDoc, Request $request): JsonResponse
    {
        $doc = SourceDoc::with(['currentVersion', 'sourceRepo:id,owner,repository,branch,active'])->find($sourceDoc);
        if (! $doc || ! $this->scope->canAccessDoc($request->user(), $doc)) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        $t0 = microtime(true);
        $situation = $this->resolver->resolve($doc, forceRefresh: true); // busta o cache da árvore
        $this->audit($doc, 'validate', 'ok', [
            'situation' => $situation['status'] ?? null,
            'blob_sha' => $situation['current_blob_sha'] ?? null,
        ], durationMs: (int) ((microtime(true) - $t0) * 1000), userId: $request->user()?->id);

        return response()->json(['data' => ['situation' => $situation]]);
    }

    /** GET /source-docs/{id}/reprocess/plan — o que aconteceria (sem enfileirar). Base da confirmação. */
    public function reprocessPlan(int $sourceDoc, Request $request): JsonResponse
    {
        $doc = SourceDoc::with('currentVersion')->find($sourceDoc);
        if (! $doc || ! $this->scope->canAccessDoc($request->user(), $doc)) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        $layer = $this->layer($request);
        $force = $request->boolean('force');

        return response()->json(['data' => $this->plan($doc, $layer, $force)]);
    }

    /** POST /source-docs/{id}/reprocess {layer,force} — enfileira (com todos os gates). */
    public function reprocess(int $sourceDoc, Request $request): JsonResponse
    {
        $doc = SourceDoc::with('currentVersion')->find($sourceDoc);
        if (! $doc) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        // C4a: fonte fora do escopo → 404 + auditoria de NEGATIVA (ação sensível de escrita/IA).
        if (! $this->scope->canAccessDoc($request->user(), $doc)) {
            $this->audit($doc, 'reprocess', 'denied', ['reason' => 'out_of_scope'], userId: $request->user()?->id);
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        $layer = $this->layer($request);
        $force = $request->boolean('force');
        $plan = $this->plan($doc, $layer, $force);

        // Refinamento #3: mesmo blob + completed + !force → NO-OP (não cria versão, não chama IA).
        if ($plan['action'] === 'reuse') {
            $this->audit($doc, 'reprocess', 'skipped', ['reused' => true, 'blob_sha' => $plan['blob_sha'], 'force' => false], layer: $layer, userId: $request->user()?->id);
            return response()->json(['data' => ['action' => 'reuse', 'message' => 'Documentação já reflete o blob atual (reutilizada).', 'plan' => $plan]]);
        }
        // Semântico: homolog-only.
        if ($this->wantsSemantic($layer) && ! $plan['ai_enabled']) {
            return response()->json(['message' => 'Reprocessamento semântico indisponível neste ambiente.', 'plan' => $plan], 403);
        }
        // Custo estimado acima do hard limit → não enfileira.
        if ($plan['blocked_reason'] === 'cost_over_limit') {
            return response()->json(['message' => 'Estimativa acima do limite — não enfileirado.', 'plan' => $plan], 422);
        }
        if ($plan['action'] === 'immutable_noop') {
            return response()->json(['message' => 'Versão concluída idêntica é imutável — nada a refazer.', 'plan' => $plan], 409);
        }

        // Idempotência + anti-concorrência: o índice parcial único (idempotency_key em queued/running)
        // rejeita uma 2ª execução equivalente (clique duplo / requests simultâneas).
        try {
            $log = SourceDocActionLog::create([
                'source_doc_id' => $doc->id, 'version_id' => $doc->current_version_id,
                'action' => 'reprocess', 'layer' => $layer, 'actor_user_id' => $request->user()?->id,
                'status' => 'queued', 'idempotency_key' => $plan['idempotency_key'],
                'params' => SourceDocActionLog::sanitize([
                    'blob_sha' => $plan['blob_sha'], 'situation' => $plan['situation'],
                    'force' => $force, 'estimated_cost_usd' => $plan['estimated_cost_usd'],
                    'hard_limit_usd' => $plan['hard_limit_usd'],
                ]),
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'source_doc_action_inflight_uq') || $e->getCode() === '23505') {
                return response()->json(['message' => 'Já há um reprocessamento equivalente em andamento.'], 409);
            }
            throw $e;
        }

        ReprocessSourceDocJob::dispatch($doc->id, $layer, $force, $log->id);

        return response()->json(['data' => ['action' => $plan['action'], 'execution_id' => $log->id, 'status' => 'queued', 'plan' => $plan]], 202);
    }

    /** GET /source-docs/{id}/execution — estado do reprocess mais recente. */
    public function execution(int $sourceDoc, Request $request): JsonResponse
    {
        // C4a: não vaza estado de execução de fonte fora do escopo.
        $doc = SourceDoc::whereKey($sourceDoc)->select('id', 'customer_id')->first();
        if (! $doc || ! $this->scope->canAccessDoc($request->user(), $doc)) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        $log = SourceDocActionLog::where('source_doc_id', $sourceDoc)->where('action', 'reprocess')
            ->latest('id')->first();
        if (! $log) {
            return response()->json(['data' => null]);
        }
        return response()->json(['data' => [
            'execution_id' => $log->id, 'status' => $log->status, 'layer' => $log->layer,
            'reason' => $log->reason, 'cost_usd' => $log->cost_usd, 'duration_ms' => $log->duration_ms,
            'new_version_id' => $log->params['new_version_id'] ?? null, 'updated_at' => $log->updated_at,
        ]]);
    }

    /** GET /source-docs/{id}/render?format=docx|pdf|md — download. */
    public function render(int $sourceDoc, Request $request, SourceDocRenderer $renderer)
    {
        $doc = SourceDoc::with('customer:id,name')->find($sourceDoc);
        if (! $doc) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        // C4a: download fora do escopo → 404 + auditoria de NEGATIVA (ação sensível).
        if (! $this->scope->canAccessDoc($request->user(), $doc)) {
            $this->audit($doc, 'download', 'denied', ['reason' => 'out_of_scope'], userId: $request->user()?->id);
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        $format = strtolower((string) $request->query('format', 'docx'));
        if (! in_array($format, ['docx', 'pdf', 'md'], true)) {
            return response()->json(['message' => 'Formato inválido.'], 422);
        }
        $docJson = DB::table('source_docs')->where('id', $doc->id)->value('documentation_json');
        $docArr = $docJson ? json_decode($docJson, true) : [];
        $isOutdated = ($this->resolver->resolve($doc)['status'] ?? null) === SourceDocStatusResolver::STATUS_OUTDATED;
        $customer = $doc->customer ? ['name' => $doc->customer->name] : [];

        $base = pathinfo($doc->filename, PATHINFO_FILENAME) ?: 'fonte';
        $t0 = microtime(true);
        [$body, $mime, $ext] = match ($format) {
            'md'  => [$renderer->markdown($docArr, $isOutdated, $customer), 'text/markdown', 'md'],
            'pdf' => [$renderer->pdf($docArr, $isOutdated, $customer), 'application/pdf', 'pdf'],
            default => [$renderer->docx($docArr, $isOutdated, $customer), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx'],
        };
        $this->audit($doc, 'download', 'ok', ['format' => $format, 'bytes' => strlen((string) $body)],
            durationMs: (int) ((microtime(true) - $t0) * 1000), userId: $request->user()?->id);

        return response($body, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => "attachment; filename=\"{$base}.{$ext}\"",
        ]);
    }

    /** GET /source-docs/{id}/git-url — URL do arquivo no commit documentado (read-only). */
    public function gitUrl(int $sourceDoc, Request $request): JsonResponse
    {
        $doc = SourceDoc::with('currentVersion:id,source_doc_id,source_commit_sha')->find($sourceDoc);
        if (! $doc) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        // C4a: git-url fora do escopo → 404 + auditoria de NEGATIVA (revela caminho/commit).
        if (! $this->scope->canAccessDoc($request->user(), $doc)) {
            $this->audit($doc, 'view_git', 'denied', ['reason' => 'out_of_scope'], userId: $request->user()?->id);
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        $ref = $doc->currentVersion?->source_commit_sha ?: $doc->branch;
        $url = "https://github.com/{$doc->owner}/{$doc->repository}/blob/{$ref}/{$doc->path}";
        $this->audit($doc, 'view_git', 'ok', ['commit_sha' => $ref], userId: $request->user()?->id);

        return response()->json(['data' => ['url' => $url]]);
    }

    /** GET /source-docs/{id}/compare?from=&to= — diff estrutural entre 2 versões (determinístico). */
    public function compare(int $sourceDoc, Request $request, SourceDiff $diff): JsonResponse
    {
        $docScope = SourceDoc::whereKey($sourceDoc)->select('id', 'customer_id')->first();
        if (! $docScope || ! $this->scope->canAccessDoc($request->user(), $docScope)) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        $from = SourceDocVersion::where('source_doc_id', $sourceDoc)->whereKey((int) $request->query('from'))->first();
        $to   = SourceDocVersion::where('source_doc_id', $sourceDoc)->whereKey((int) $request->query('to'))->first();
        if (! $from || ! $to) {
            return response()->json(['message' => 'Versões inválidas para este fonte.'], 422);
        }
        // Determinístico puro (SourceDiff sobre os deterministic_json). SEM IA.
        $result = $diff->compare($from->deterministic_json, $to->deterministic_json ?? [], null, '');

        return response()->json(['data' => [
            'from' => ['id' => $from->id, 'commit_sha' => $from->source_commit_sha, 'created_at' => $from->created_at],
            'to'   => ['id' => $to->id, 'commit_sha' => $to->source_commit_sha, 'created_at' => $to->created_at],
            'diff' => $result,
        ]]);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function layer(Request $request): string
    {
        $l = (string) $request->query('layer', $request->input('layer', 'both'));
        return in_array($l, ['deterministic', 'semantic', 'both'], true) ? $l : 'both';
    }

    private function wantsSemantic(string $layer): bool
    {
        return $layer === 'semantic' || $layer === 'both';
    }

    /** Monta o plano do reprocess (o que aconteceria) — base da confirmação e dos gates. */
    private function plan(SourceDoc $doc, string $layer, bool $force): array
    {
        $ver = $doc->currentVersion;
        $documentedBlob = $ver?->source_blob_sha;
        $status = $ver?->analysis_status;

        // Blob atual do arquivo no HEAD do branch (determinístico; o GitHub devolve o sha).
        $current = $this->auth->getFileWithSha($doc->owner, $doc->repository, $doc->branch, $doc->path);
        $currentBlob = $current['blob_sha'] ?? null;
        $situation = $this->resolver->resolve($doc)['status'] ?? null;

        $aiEnabled = (bool) config('services.source_doc_ai.enabled', false)
            && in_array((string) config('services.source_doc_ai.environment'), (array) config('services.source_doc_ai.allowed_environments', ['homolog']), true);
        $hardLimit = (float) config('services.source_doc_ai.hard_limit_usd', 0.30);
        $promptVersion = (int) config('services.source_doc_ai.prompt_version', 2);

        // Decisão (refinamento #3 + imutabilidade):
        $action = 'reprocess_in_place';
        if (in_array($status, ['partial', 'failed', 'analyzing'], true)) {
            $action = 'reprocess_in_place';                 // versão mutável
        } elseif ($status === 'completed') {
            if ($currentBlob !== null && $currentBlob !== $documentedBlob) {
                $action = 'new_version';                     // código mudou → nova versão
            } elseif ($force) {
                $action = 'immutable_noop';                  // mesmo blob concluído: imutável (motor congelado)
            } else {
                $action = 'reuse';                           // default: no-op/reutiliza
            }
        }

        // Estimativa APROXIMADA (o motor aplica o hard-limit real durante a análise).
        $est = $this->wantsSemantic($layer) && $action !== 'reuse' && $action !== 'immutable_noop'
            ? $this->estimateSemanticCost($ver) : 0.0;
        $blocked = ($this->wantsSemantic($layer) && $aiEnabled && $est > $hardLimit) ? 'cost_over_limit' : null;

        return [
            'layer' => $layer, 'force' => $force,
            'action' => $action,                            // reuse | new_version | reprocess_in_place | immutable_noop
            'will_create_version' => $action === 'new_version',
            'blob_sha' => $currentBlob, 'documented_blob_sha' => $documentedBlob,
            'analysis_status' => $status, 'situation' => $situation,
            'ai_enabled' => $aiEnabled, 'environment' => (string) config('services.source_doc_ai.environment'),
            'estimated_cost_usd' => round($est, 4), 'estimated_cost_note' => 'aproximada; o motor aplica o hard-limit real',
            'hard_limit_usd' => $hardLimit, 'blocked_reason' => $blocked,
            'idempotency_key' => hash('sha256', "{$doc->id}|{$currentBlob}|{$layer}|{$promptVersion}"),
        ];
    }

    /** Estimativa aproximada do custo semântico (heurística; o motor enforce o hard-limit real). */
    private function estimateSemanticCost(?SourceDocVersion $ver): float
    {
        if (! $ver || ! is_array($ver->deterministic_json)) {
            return 0.0;
        }
        $funcs = count($ver->deterministic_json['functions'] ?? []);
        $detBytes = strlen(json_encode($ver->deterministic_json));
        // heurística grosseira: ~tokens de entrada pelos fatos + saída pelas finalidades das funções.
        $inTokens = $detBytes / 3.2;
        $outTokens = min($funcs, (int) config('services.source_doc_ai.max_relevant_functions', 12)) * 120 + 800;
        // preços aproximados sonnet (US$/1k): in 0.003, out 0.015.
        return ($inTokens / 1000) * 0.003 + ($outTokens / 1000) * 0.015;
    }

    private function audit(SourceDoc $doc, string $action, string $status, array $params = [], ?string $layer = null, ?int $durationMs = null, ?int $userId = null, ?float $cost = null, ?int $versionId = null): void
    {
        SourceDocActionLog::create([
            'source_doc_id' => $doc->id, 'version_id' => $versionId ?? $doc->current_version_id,
            'action' => $action, 'layer' => $layer, 'actor_user_id' => $userId,
            'status' => $status, 'duration_ms' => $durationMs, 'cost_usd' => $cost,
            'params' => SourceDocActionLog::sanitize($params),
        ]);
    }
}
