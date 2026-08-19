<?php

namespace App\Http\Controllers;

use App\Jobs\ReprocessSourceDocJob;
use App\Models\SourceDoc;
use App\Models\SourceDocActionLog;
use App\Models\SourceDocVersion;
use App\SourceCode\Analyzer\SourceDiff;
use App\SourceCode\Cost\SourceCostGovernor;
use App\SourceCode\Exceptions\SourceIntegrationException;
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
        private SourceCostGovernor $governor,
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

        // Frente A — GOVERNANÇA DE CUSTO POR FONTE (orquestração; motor congelado intocado). Só a camada
        // semântica gasta IA: reserva atômica antes de enfileirar; se estoura o limite operacional por
        // fonte e a aprovação está ligada → cria solicitação e NÃO enfileira (nenhuma chamada de IA).
        if ($this->wantsSemantic($layer) && $plan['ai_enabled']) {
            $decision = $this->governor->authorizeStep($doc, 'reprocess', (float) $plan['estimated_cost_usd'], $request->user()?->id, [
                'reason' => 'reprocessamento semântico',
                'completeness_level' => $doc->currentVersion?->analysis_status,
            ]);
            if ($decision->needsApproval()) {
                return response()->json(['message' => 'Custo acima do limite por fonte — enviado para aprovação.', 'action' => 'awaiting_cost_approval', 'approval_id' => $decision->approval?->id, 'plan' => $plan, 'cost' => $decision->settings->toArray()], 202);
            }
            if ($decision->outcome === 'deny_partial') {
                return response()->json(['message' => 'Custo acima do limite por fonte — aprovação desligada; não enfileirado.', 'action' => 'cost_denied_partial', 'plan' => $plan], 422);
            }
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

    /**
     * POST /source-docs/{id}/topup — TOP-UP/RECOVERY seletivo de fonte PARCIAL (enriquece o semantic_json
     * existente reprocessando só blocos quebrados + funções em missing técnico). Síncrono; devolve
     * before × after. Homolog-only. Caminho ESPECÍFICO de enriquecimento (não é o reprocess genérico).
     */
    public function topup(int $sourceDoc, Request $request): JsonResponse
    {
        $doc = SourceDoc::with('currentVersion')->find($sourceDoc);
        if (! $doc) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        if (! $this->scope->canAccessDoc($request->user(), $doc)) {
            $this->audit($doc, 'topup', 'denied', ['reason' => 'out_of_scope'], userId: $request->user()?->id);
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        $ver = $doc->currentVersion;
        if (! $ver || ! is_array($ver->semantic_json)) {
            return response()->json(['message' => 'Fonte sem semântica para enriquecer.'], 422);
        }
        // Frente A — GOVERNANÇA DE CUSTO POR FONTE: o top-up é um PASSO pago fresco (~US$0,30). Reserva
        // antes de rodar; se estoura o limite operacional por fonte → aprovação (não roda a IA).
        $stepEstimate = (float) config('services.source_doc_ai.hard_limit_usd', 0.30);
        $decision = $this->governor->authorizeStep($doc, 'top_up', $stepEstimate, $request->user()?->id, [
            'reason' => 'top-up de fonte parcial',
            'completeness_level' => $ver->semantic_json['documentary_completeness']['level'] ?? null,
            'gaps' => $ver->semantic_json['documentary_completeness']['missing'] ?? null,
        ]);
        if ($decision->needsApproval()) {
            return response()->json(['message' => 'Custo acima do limite por fonte — enviado para aprovação.', 'action' => 'awaiting_cost_approval', 'approval_id' => $decision->approval?->id, 'cost' => $decision->settings->toArray()], 202);
        }
        if ($decision->outcome === 'deny_partial') {
            return response()->json(['message' => 'Custo acima do limite por fonte — aprovação desligada.', 'action' => 'cost_denied_partial'], 422);
        }
        $pipeline = app(\App\SourceCode\SourceDocPipeline::class);
        $before = $this->topupSnapshot($ver->semantic_json);
        $t0 = microtime(true);
        try {
            $ver = $pipeline->topUpVersion($ver);
        } catch (\Throwable $e) {
            $this->governor->release($doc, $decision->reservedUsd); // libera reserva sem gasto
            return response()->json(['message' => 'Falha no top-up.', 'error' => mb_substr($e->getMessage(), 0, 200)], 500);
        }
        $after = $this->topupSnapshot($ver->semantic_json);
        // liquida a reserva com o custo real do passo de top-up.
        $realStep = (float) ($ver->semantic_json['usage']['topup_cost_usd'] ?? $after['topup_cost_usd'] ?? 0.0);
        $this->governor->settle($doc, $decision->reservedUsd, $realStep);
        $this->audit($doc, 'topup', 'ok', ['before' => $before['documentary_completeness'] ?? null, 'after' => $after['documentary_completeness'] ?? null], durationMs: (int) ((microtime(true) - $t0) * 1000), userId: $request->user()?->id);
        return response()->json(['data' => ['id' => $doc->id, 'file' => $doc->filename ?: $doc->path, 'before' => $before, 'after' => $after]]);
    }

    /** Métricas comparáveis do semantic_json p/ before × after do top-up. */
    private function topupSnapshot($sem): array
    {
        $sem = is_array($sem) ? $sem : [];
        $trace = (array) ($sem['funcoes_trace'] ?? []);
        $tech = ['cost_budget', 'truncated_unrecovered', 'deepen_call_budget', 'simple_truncated'];
        $techMiss = count(array_filter($trace['missing'] ?? [], fn ($m) => in_array($m['reason'] ?? '', $tech, true)));
        $dc = (array) ($sem['documentary_completeness'] ?? []);
        $u = (array) ($sem['usage'] ?? []);
        return [
            'status' => $sem['status'] ?? null,
            'partial_reason' => $sem['partial_reason'] ?? null,
            'strategy' => $sem['strategy'] ?? null,
            'block_status' => $sem['block_status'] ?? null,
            'completed' => count($trace['completed'] ?? []),
            'not_identified' => count($trace['not_identified'] ?? []),
            'missing_total' => count($trace['missing'] ?? []),
            'missing_tecnico' => $techMiss,
            'regras' => count($sem['regras_negocio'] ?? []),
            'deps' => count($sem['dependencias_criticas'] ?? []),
            'risco' => ! empty(($sem['risco_alteracao']['fatores'] ?? [])),
            'documentary_completeness' => $dc['level'] ?? null,
            'dc_missing_dims' => $dc['missing'] ?? [],
            'rejeicoes_evidencia' => (int) ($sem['validation']['rejected_count'] ?? 0),
            'cost_usd' => $u['actual_cost_usd'] ?? null,
            'topup_cost_usd' => $u['topup_cost_usd'] ?? null,
            'topup_calls' => $u['topup_calls'] ?? null,
            'calls' => $u['calls'] ?? null,
        ];
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

    /**
     * POST /source-docs/{id}/publish-git — publica o MD da documentação numa branch dedicada
     * (minutor-docs) em .minutor/<path>.md. NUNCA escreve na branch de produção do cliente.
     */
    public function publishGit(int $sourceDoc, Request $request, SourceDocRenderer $renderer): JsonResponse
    {
        $doc = SourceDoc::with('customer:id,name')->find($sourceDoc);
        if (! $doc) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        if (! $this->scope->canAccessDoc($request->user(), $doc)) {
            $this->audit($doc, 'publish_git', 'denied', ['reason' => 'out_of_scope'], userId: $request->user()?->id);
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }

        $docJson = DB::table('source_docs')->where('id', $doc->id)->value('documentation_json');
        $docArr = $docJson ? json_decode($docJson, true) : [];
        $isOutdated = ($this->resolver->resolve($doc)['status'] ?? null) === SourceDocStatusResolver::STATUS_OUTDATED;
        $customer = $doc->customer ? ['name' => $doc->customer->name] : [];
        $md = $renderer->markdown($docArr, $isOutdated, $customer);

        $branch = 'minutor-docs';
        $path = '.minutor/' . ltrim(str_replace('\\', '/', (string) $doc->path), '/') . '.md';

        try {
            $this->auth->ensureBranch($doc->owner, $doc->repository, $branch);
            $sha = $this->auth->commitFiles($doc->owner, $doc->repository, $branch, [$path => $md], "docs: {$doc->filename} — documentação técnica (Minutor)");
        } catch (SourceIntegrationException $e) {
            $this->audit($doc, 'publish_git', 'error', ['reason' => $e->getMessage()], userId: $request->user()?->id);
            $msg = str_contains($e->getMessage(), 'Contents') || str_contains($e->getMessage(), 'contents')
                ? 'O GitHub App da Central não tem permissão de escrita (Contents: write) neste repositório. Conceda a permissão na instalação do App e tente novamente.'
                : 'Não foi possível publicar no git: ' . $e->getMessage();
            return response()->json(['message' => $msg], 422);
        }

        $this->audit($doc, 'publish_git', 'ok', ['branch' => $branch, 'path' => $path, 'commit' => $sha], userId: $request->user()?->id);
        $url = "https://github.com/{$doc->owner}/{$doc->repository}/blob/{$branch}/" . implode('/', array_map('rawurlencode', explode('/', $path)));

        return response()->json(['data' => ['branch' => $branch, 'path' => $path, 'commit' => $sha, 'url' => $url]]);
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

    /** GET /source-docs/{id}/source — código da versão atual (SOMENTE LEITURA, escopado). F3 aba Código. */
    public function source(int $sourceDoc, Request $request): JsonResponse
    {
        $doc = SourceDoc::with('currentVersion:id,source_doc_id,source_commit_sha')->find($sourceDoc);
        if (! $doc) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        if (! $this->scope->canAccessDoc($request->user(), $doc)) {
            $this->audit($doc, 'view_git', 'denied', ['reason' => 'out_of_scope'], userId: $request->user()?->id);
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        $ref = $doc->currentVersion?->source_commit_sha ?: $doc->branch;
        $content = $this->auth->getFileContent($doc->owner, $doc->repository, $ref, $doc->path);
        if ($content === null) {
            return response()->json(['message' => 'Código indisponível no momento.'], 502);
        }
        $this->audit($doc, 'view_git', 'ok', ['commit_sha' => $ref, 'bytes' => strlen($content)], userId: $request->user()?->id);

        return response()->json(['data' => [
            'content' => $content, 'path' => $doc->path, 'lang' => $doc->lang, 'commit_sha' => $ref, 'bytes' => strlen($content),
        ]]);
    }

    /**
     * POST /source-docs/{id}/manual-semantic {semantic, responsavel?} — documentação MANUAL (sem IA):
     * grava um semantic_json montado fora do motor na versão atual (reusa o determinístico), reconsolida
     * e reindexa. NÃO chama IA, não gasta crédito. Bloqueado em produção (só homolog/dev).
     */
    public function manualSemantic(int $sourceDoc, Request $request): JsonResponse
    {
        if (app()->environment('production')) {
            return response()->json(['message' => 'Documentação manual não é permitida em produção.'], 403);
        }
        $doc = SourceDoc::with('currentVersion')->find($sourceDoc);
        if (! $doc || ! $this->scope->canAccessDoc($request->user(), $doc)) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        $ver = $doc->currentVersion;
        if (! $ver || ! is_array($ver->deterministic_json)) {
            return response()->json(['message' => 'Fonte sem versão determinística para documentar.'], 422);
        }
        $data = $request->validate([
            'semantic' => ['required', 'array'],
            'responsavel' => ['nullable', 'string', 'max:120'],
        ]);

        $out = app(\App\SourceCode\SourceDocPipeline::class)->applyManualSemantic(
            $ver, $data['semantic'], $data['responsavel'] ?? 'Documentação manual (sem IA)'
        );
        $this->audit($doc, 'reprocess', 'ok', ['mode' => 'manual_semantic', 'version_id' => $out->id], userId: $request->user()?->id);

        return response()->json(['data' => [
            'source_doc_id' => $doc->id, 'version_id' => $out->id,
            'analysis_status' => $out->analysis_status,
            'has_semantic' => ! empty($out->semantic_json),
        ]]);
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
        // O analyzer (Bloco 4.2) NÃO envia o determinístico inteiro: manda COMPACT FACTS (limitados às
        // ~12 funções relevantes) + código CAPADO (entrypoint + budget de aprofundamento) em 4 blocos.
        // Estimar pelo det inteiro superestima MUITO e bloqueava indevidamente fontes grandes.
        $det = $ver->deterministic_json;
        $ci = (float) config('services.source_doc_ai.cost_input_per_mtok', 3.0);
        $co = (float) config('services.source_doc_ai.cost_output_per_mtok', 15.0);
        // facts compactos: subconjunto do det, com teto (não crescem com o tamanho do fonte).
        $factsTok = min(strlen(json_encode($det)) / 3.2, 6000);
        $entCodeTok = (int) config('services.source_doc_ai.entendimento_code_budget_chars', 9000) / 1.6;
        $deepenTok  = (int) config('services.source_doc_ai.deepen_code_budget_tokens', 12000);
        // facts vão nos 3 blocos narrativos + código do entrypoint (bloco 1) + código do aprofundamento.
        $inTokens  = $factsTok * 3 + $entCodeTok + $deepenTok;
        // 4 blocos de saída (entendimento, regras, deps/risco, funções).
        $outTokens = 3000 + 2600 + 3000 + 2600;
        return $inTokens / 1e6 * $ci + $outTokens / 1e6 * $co;
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
