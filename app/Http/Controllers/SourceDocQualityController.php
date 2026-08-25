<?php

namespace App\Http\Controllers;

use App\Models\SourceDoc;
use App\Models\SourceDocActionLog;
use App\Models\SourceDocQualityAnalysis;
use App\Models\SourceDocQualityFinding;
use App\SourceCode\Exceptions\CodeAnalysisException;
use App\SourceCode\GithubAppAuth;
use App\SourceCode\SourceDocCustomerScope;
use App\SourceCode\SourceDocStatusResolver;
use App\Services\SourceDocQualityService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Análise de Qualidade (CodeAnalysis) ↔ Central de Fontes — backend do Minutor (Gate A2).
 *
 * Responsabilidade do Minutor: autoridade de usuário/cliente/source_doc/versão/permissão/auditoria.
 * O CodeAnalysis é a autoridade técnica (execução/analyzer/findings). O browser NUNCA envia o
 * fonte: o backend obtém o conteúdo da versão vigente (server-to-server) e repassa ao serviço.
 * "Qualidade pertence a uma versão específica do fonte" → tudo é chaveado por source_blob_sha.
 */
class SourceDocQualityController extends Controller
{
    public function __construct(
        private GithubAppAuth $auth,
        private SourceDocCustomerScope $scope,
        private SourceDocStatusResolver $resolver,
        private SourceDocQualityService $service,
    ) {
    }

    /** GET /source-docs/{sourceDoc}/quality — estado da qualidade da VERSÃO vigente. */
    public function show(Request $request, int $sourceDoc): JsonResponse
    {
        $doc = $this->loadDoc($sourceDoc);
        if (! $doc || ! $this->scope->canAccessDoc($request->user(), $doc)) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }

        $currentBlob = $this->resolver->resolve($doc)['current_blob_sha'] ?? null;
        $latest = $this->latestFor($doc);

        // Polling limitado: só quando há job em andamento, e só ao carregar (uma chamada).
        if ($latest && $latest->isInflight() && $latest->external_job_id) {
            $this->refreshFromRemote($latest);
        }

        return response()->json(['data' => $this->stateView($doc, $currentBlob, $latest)]);
    }

    /** POST /source-docs/{sourceDoc}/quality — dispara a análise da versão vigente. */
    public function run(Request $request, int $sourceDoc): JsonResponse
    {
        $doc = $this->loadDoc($sourceDoc);
        if (! $doc || ! $this->scope->canAccessDoc($request->user(), $doc)) {
            // não vaza existência; e audita a negação por escopo
            if ($doc) {
                $this->audit($doc, 'denied', ['reason' => 'out_of_scope'], $request->user()?->id);
            }
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }

        $force = $request->boolean('force');

        // Conteúdo da versão vigente, obtido SERVER-SIDE (nunca pelo browser).
        $ref = $doc->currentVersion?->source_commit_sha ?: $doc->branch;
        $fetched = $this->auth->getFileWithSha($doc->owner, $doc->repository, $ref, $doc->path);
        if ($fetched === null || ($fetched['content'] ?? null) === null) {
            $this->audit($doc, 'skipped', ['reason' => 'source_unavailable'], $request->user()?->id);
            return response()->json(['message' => 'Código indisponível no momento.'], 502);
        }
        $content = (string) $fetched['content'];
        $blob = (string) ($fetched['blob_sha'] ?? '') ?: $this->gitBlobSha($content);

        // Reuse: análise concluída idêntica (mesmo blob) → devolve sem re-rodar (salvo force).
        if (! $force) {
            $done = SourceDocQualityAnalysis::where('source_doc_id', $doc->id)
                ->where('source_blob_sha', $blob)
                ->where('status', SourceDocQualityAnalysis::STATUS_COMPLETED)
                ->latest('id')->first();
            if ($done) {
                $currentBlob = $this->resolver->resolve($doc)['current_blob_sha'] ?? null;
                return response()->json(['data' => $this->stateView($doc, $currentBlob, $done), 'reused' => true], 200);
            }
            // Dedup: já há um job em andamento p/ este blob → devolve o mesmo (anti-duplo-clique).
            $inflight = SourceDocQualityAnalysis::where('source_doc_id', $doc->id)
                ->where('source_blob_sha', $blob)
                ->whereIn('status', SourceDocQualityAnalysis::INFLIGHT)
                ->latest('id')->first();
            if ($inflight) {
                $currentBlob = $this->resolver->resolve($doc)['current_blob_sha'] ?? null;
                return response()->json(['data' => $this->stateView($doc, $currentBlob, $inflight), 'reused' => true], 202);
            }
        }

        // Cria o registro local (queued). O índice único parcial garante 1 in-flight por (doc, blob).
        try {
            $record = SourceDocQualityAnalysis::create([
                'source_doc_id'         => $doc->id,
                'source_doc_version_id' => $doc->current_version_id,
                'source_blob_sha'       => $blob,
                'status'                => SourceDocQualityAnalysis::STATUS_QUEUED,
                'requested_by'          => $request->user()?->id,
                'requested_at'          => now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $inflight = SourceDocQualityAnalysis::where('source_doc_id', $doc->id)
                    ->where('source_blob_sha', $blob)
                    ->whereIn('status', SourceDocQualityAnalysis::INFLIGHT)
                    ->latest('id')->first();
                $currentBlob = $this->resolver->resolve($doc)['current_blob_sha'] ?? null;
                return response()->json(['data' => $this->stateView($doc, $currentBlob, $inflight), 'reused' => true], 202);
            }
            throw $e;
        }

        // Chama o CodeAnalysis (server-to-server). Sem retry cego (evita job duplicado).
        try {
            $resp = $this->service->analyze(
                filename: basename($doc->path),
                content: $content,
                context: ['ref' => "sd:{$doc->id}", 'version' => $doc->current_version_id],
                force: $force,
            );
        } catch (CodeAnalysisException $e) {
            // Falha ANTES de haver job remoto: registro NÃO pode ficar "running" à toa → failed.
            $record->update([
                'status'        => SourceDocQualityAnalysis::STATUS_FAILED,
                'failed_at'     => now(),
                'error_code'    => $e->errorCode,
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ]);
            $this->audit($doc, 'denied', ['reason' => $e->errorCode], $request->user()?->id);
            $status = $e->unavailable ? 503 : 502;
            return response()->json([
                'message' => 'Não foi possível iniciar a análise de qualidade.',
                'error'   => $e->errorCode,
                'data'    => $this->stateView($doc, $blob, $record->fresh()),
            ], $status);
        }

        $this->applyRemote($record, $resp);
        $this->audit($doc, 'ok', ['blob_sha' => $blob], $request->user()?->id);

        $currentBlob = $this->resolver->resolve($doc)['current_blob_sha'] ?? null;
        return response()->json(['data' => $this->stateView($doc, $currentBlob, $record->fresh())], 202);
    }

    /**
     * Campos que PODEM revelar código-fonte. Removidos do payload quando o usuário não tem
     * source_docs.view_git (a política de código-fonte é protegida NO BACKEND, não só no FE).
     */
    private const CODE_REVEALING_KEYS = [
        'snippet', 'source', 'code', 'excerpt', 'context', 'line_content', 'content', 'example',
    ];

    /**
     * GET /source-docs/{sourceDoc}/quality/{analysis}/findings
     * Detalhe dos achados de UMA análise. Proxy server-to-server ao CodeAnalysis (A1) via
     * external_job_id. Achados NÃO são persistidos no Postgres. Gating de código no backend.
     */
    public function findings(Request $request, int $sourceDoc, int $analysis): JsonResponse
    {
        $doc = $this->loadDoc($sourceDoc);
        if (! $doc || ! $this->scope->canAccessDoc($request->user(), $doc)) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }

        // Anti-IDOR: a análise TEM de pertencer a esta fonte (senão 404, não vaza existência).
        $rec = SourceDocQualityAnalysis::where('id', $analysis)
            ->where('source_doc_id', $doc->id)->first();
        if (! $rec) {
            return response()->json(['message' => 'Análise não encontrada.'], 404);
        }

        $canViewCode = (bool) $request->user()?->hasAccess('source_docs.view_git');

        // P2: AUTORIDADE = Postgres. Achados persistidos sobrevivem a restart/deploy do CodeAnalysis.
        $persisted = $rec->findings; // ordenados por position; NUNCA contêm código
        if ($persisted->isNotEmpty()) {
            $findings = $persisted->map(fn (SourceDocQualityFinding $f) => $this->findingView($f))->all();
            // Snippet (code) só p/ view_git e SÓ sob demanda/ao vivo — jamais lido do banco.
            if ($canViewCode) {
                $findings = $this->enrichSnippets($rec, $findings);
            }
            return response()->json(['data' => [
                'analysis_id'     => $rec->id,
                'external_job_id' => $rec->external_job_id,
                'status'          => $rec->status,
                'view_git'        => $canViewCode,
                'source'          => 'persisted',
                'findings'        => array_values($findings),
            ]]);
        }

        // Sem achados persistidos e sem job remoto → nada a mostrar (queued/failed antes de criar).
        if (! $rec->external_job_id) {
            return response()->json(['data' => [
                'analysis_id' => $rec->id, 'external_job_id' => null, 'status' => $rec->status,
                'view_git' => $canViewCode, 'source' => 'none', 'findings' => [],
            ]]);
        }

        // Legado (análise concluída ANTES do P2) → busca ao vivo e migra oportunisticamente.
        try {
            $remote = $this->service->getJob((string) $rec->external_job_id);
        } catch (CodeAnalysisException $e) {
            $status = $e->unavailable ? 503 : 502;
            return response()->json(['message' => 'Não foi possível obter os achados.', 'error' => $e->errorCode], $status);
        }
        if ($remote === null) {
            // Job perdido (404) — reconcilia se estava inflight; não há achados persistidos.
            $this->reconcileLostJob($rec);
            return response()->json(['data' => [
                'analysis_id' => $rec->id, 'external_job_id' => $rec->external_job_id,
                'status' => $rec->fresh()->status, 'view_git' => $canViewCode, 'source' => 'none', 'findings' => [],
            ]]);
        }
        $raw = is_array($remote['findings'] ?? null) ? array_values($remote['findings']) : [];
        if (($remote['status'] ?? null) === SourceDocQualityAnalysis::STATUS_COMPLETED
            && $raw && $rec->findings()->doesntExist()) {
            $this->persistFindings($rec, $raw); // migra o legado p/ o Postgres
        }
        $findings = $canViewCode ? $raw : array_map(fn ($f) => $this->stripCode($f), $raw);
        return response()->json(['data' => [
            'analysis_id'     => $rec->id,
            'external_job_id' => $rec->external_job_id,
            'status'          => $remote['status'] ?? $rec->status,
            'view_git'        => $canViewCode,
            'source'          => 'remote',
            'findings'        => array_values($findings),
        ]]);
    }

    /** Achado persistido → shape que a UI consome (mesmas chaves do A1, SEM snippet). */
    private function findingView(SourceDocQualityFinding $f): array
    {
        $base = [
            'rule'              => $f->rule,
            'severity'          => $f->severity,
            'analyzer_severity' => $f->analyzer_severity,
            'category'          => $f->category,
            'title'             => $f->title,
            'description'       => $f->description,
            'recommendation'    => $f->recommendation,
            'file'              => $f->file,
            'line'              => $f->line,
            'start_line'        => $f->start_line,
            'col'               => $f->col,
            'count'             => $f->occurrences,
        ];
        if (is_array($f->meta)) {
            $base = array_merge($f->meta, $base); // extras não sensíveis (meta nunca tem código)
        }
        return $base;
    }

    /**
     * Snippet (código) SÓ para view_git, best-effort AO VIVO do CodeAnalysis — nunca do banco.
     * Se o CA estiver indisponível/reiniciado, os metadados já foram servidos; o trecho fica ausente.
     * SUBITEM TÉCNICO (futuro): reconstruir o trecho a partir do source_blob_sha histórico (get-blob
     * by-sha) para o snippet sobreviver a restart do CA — hoje NÃO existe no contrato GithubAppAuth,
     * então não é implementado aqui e NADA de código é gravado no Postgres.
     */
    private function enrichSnippets(SourceDocQualityAnalysis $rec, array $findings): array
    {
        if (! $rec->external_job_id) {
            return $findings;
        }
        try {
            $remote = $this->service->getJob((string) $rec->external_job_id);
        } catch (\Throwable) {
            return $findings; // CA indisponível → sem snippet (metadados já entregues)
        }
        if (! is_array($remote) || ! is_array($remote['findings'] ?? null)) {
            return $findings; // job perdido (404→null) ou sem findings → mantém metadados sem snippet
        }
        $raw = array_values($remote['findings']);
        foreach ($findings as $i => $f) {
            $snip = $raw[$i]['snippet'] ?? null; // alinhado por position (mesma ordem)
            if (is_string($snip) && $snip !== '') {
                $findings[$i]['snippet'] = $snip;
            }
        }
        return $findings;
    }

    /**
     * Reconciliação de job perdido (P2): análise inflight cujo job NÃO existe mais no CA (404
     * definitivo, tipicamente restart do serviço efêmero) NÃO pode ficar eterna em queued/running.
     * Vira failed com error_code='job_lost' — distinto de 'remote_failed' (analyzer realmente falhou)
     * e de indisponibilidade transitória (5xx/timeout → mantém estado, tratado no chamador).
     */
    private function reconcileLostJob(SourceDocQualityAnalysis $rec): void
    {
        if ($rec->isInflight()) {
            $rec->update([
                'status'        => SourceDocQualityAnalysis::STATUS_FAILED,
                'failed_at'     => now(),
                'error_code'    => 'job_lost',
                'error_message' => 'Job perdido no serviço de análise (reinício/expiração).',
            ]);
        }
    }

    /** Remove QUALQUER campo que possa revelar código; preserva só metadados seguros. */
    private function stripCode(array $finding): array
    {
        foreach (self::CODE_REVEALING_KEYS as $k) {
            unset($finding[$k]);
        }
        return $finding;
    }

    /** GET /source-docs/{sourceDoc}/quality/history — análises da fonte (todas as versões). */
    public function history(Request $request, int $sourceDoc): JsonResponse
    {
        $doc = $this->loadDoc($sourceDoc);
        if (! $doc || ! $this->scope->canAccessDoc($request->user(), $doc)) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        $currentBlob = $this->resolver->resolve($doc)['current_blob_sha'] ?? null;
        $items = SourceDocQualityAnalysis::where('source_doc_id', $doc->id)
            ->latest('id')->limit(50)->get()
            ->map(fn (SourceDocQualityAnalysis $r) => [
                'id'              => $r->id,
                'status'          => $r->status,
                'source_blob_sha' => $r->source_blob_sha,
                'score'           => $r->score,
                'grade'           => $r->grade,
                'risk'            => $r->risk,
                'requested_at'    => optional($r->requested_at)->toIso8601String(),
                'completed_at'    => optional($r->completed_at)->toIso8601String(),
                'stale'           => ! $r->matchesBlob($currentBlob),
            ]);
        return response()->json(['data' => [
            'source_doc_id'    => $doc->id,
            'current_blob_sha' => $currentBlob,
            'items'            => $items,
        ]]);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function loadDoc(int $id): ?SourceDoc
    {
        return SourceDoc::with('currentVersion:id,source_doc_id,source_commit_sha,source_blob_sha')
            ->select('id', 'customer_id', 'owner', 'repository', 'branch', 'path', 'current_version_id')
            ->find($id);
    }

    private function latestFor(SourceDoc $doc): ?SourceDocQualityAnalysis
    {
        return SourceDocQualityAnalysis::where('source_doc_id', $doc->id)->latest('id')->first();
    }

    /** Consulta o job remoto UMA vez e sincroniza o registro local (polling bounded). */
    private function refreshFromRemote(SourceDocQualityAnalysis $record): void
    {
        try {
            $remote = $this->service->getJob((string) $record->external_job_id);
        } catch (CodeAnalysisException) {
            return; // indisponibilidade TRANSITÓRIA (5xx/timeout/conn) — mantém estado; não reconcilia
        }
        if ($remote === null) {
            // 404 DEFINITIVO: o job não existe mais no CA (restart do store efêmero) → não fica eterno.
            $this->reconcileLostJob($record);
            return;
        }
        $this->applyRemote($record, $remote);
    }

    /** Mapeia a resposta do CodeAnalysis (A1) para o registro local. */
    private function applyRemote(SourceDocQualityAnalysis $record, array $r): void
    {
        $status = $r['status'] ?? $record->status;
        $engine = $r['engine'] ?? [];
        $counts = $r['counts'] ?? [];
        $data = [
            'status'         => $status,
            'external_job_id' => $r['job_id'] ?? $record->external_job_id,
            'engine'         => $engine['name'] ?? $record->engine,
            'engine_version' => $engine['image'] ?? $record->engine_version,
            'rules_version'  => $engine['rules_version'] ?? $record->rules_version,
        ];
        if (! empty($r['started_at']) && ! $record->started_at) {
            $data['started_at'] = $this->ts($r['started_at']);
        }
        if ($status === SourceDocQualityAnalysis::STATUS_COMPLETED) {
            $data['score']             = $r['score'] ?? null;
            $data['grade']             = $r['grade'] ?? null;
            $data['risk']              = $r['risk'] ?? null;
            $data['n_critical']        = $counts['critical'] ?? null;
            $data['n_warnings']        = $counts['warnings'] ?? null;
            $data['n_recommendations'] = $counts['recommendations'] ?? null;
            $data['n_findings']        = $counts['total'] ?? null;
            $data['completed_at']      = $this->ts($r['finished_at'] ?? null) ?? now();
        }
        if ($status === SourceDocQualityAnalysis::STATUS_FAILED) {
            $data['failed_at']     = $this->ts($r['finished_at'] ?? null) ?? now();
            $data['error_message'] = mb_substr((string) ($r['error'] ?? 'Falha na análise.'), 0, 500);
            $data['error_code']    = 'remote_failed';
        }
        $record->update($data);

        // P2: ao concluir, persistir os achados no Postgres (autoridade histórica). getJob() já traz
        // 'findings' quando completed. Idempotente: só grava se ainda não houver achados persistidos.
        if ($status === SourceDocQualityAnalysis::STATUS_COMPLETED
            && is_array($r['findings'] ?? null)
            && $record->findings()->doesntExist()) {
            $this->persistFindings($record, $r['findings']);
        }
    }

    /**
     * P2 — persiste os achados no Postgres SEM código-fonte (só metadados). Substitui de forma
     * idempotente (delete+insert em transação). NUNCA grava snippet/código (CODE_REVEALING_KEYS).
     *
     * @param  array<int,array<string,mixed>>  $raw  achados crus do CodeAnalysis
     */
    private function persistFindings(SourceDocQualityAnalysis $record, array $raw): void
    {
        DB::transaction(function () use ($record, $raw) {
            $record->findings()->delete();
            $pos = 0;
            foreach ($raw as $f) {
                if (! is_array($f)) {
                    continue;
                }
                // Metadados extras NÃO sensíveis → meta (removendo qualquer chave que revele código).
                $meta = $f;
                foreach (self::CODE_REVEALING_KEYS as $k) {
                    unset($meta[$k]);
                }
                foreach (['rule', 'severity', 'analyzer_severity', 'category', 'title', 'description',
                    'recommendation', 'file', 'line', 'start_line', 'col', 'column', 'count'] as $k) {
                    unset($meta[$k]);
                }

                SourceDocQualityFinding::create([
                    'source_doc_quality_analysis_id' => $record->id,
                    'position'          => $pos++,
                    'rule'              => $this->str($f['rule'] ?? null, 255),
                    'severity'          => $this->str($f['severity'] ?? null, 16),
                    'analyzer_severity' => $this->str($f['analyzer_severity'] ?? null, 32),
                    'category'          => $this->str($f['category'] ?? null, 255),
                    'title'             => $this->str($f['title'] ?? null, 255),
                    'description'       => $f['description'] ?? null,
                    'recommendation'    => $f['recommendation'] ?? null,
                    'file'              => $this->str($f['file'] ?? null, 255),
                    'line'              => $this->intOrNull($f['line'] ?? null),
                    'start_line'        => $this->intOrNull($f['start_line'] ?? null),
                    'col'               => $this->intOrNull($f['col'] ?? $f['column'] ?? null),
                    'occurrences'       => $this->intOrNull($f['count'] ?? null),
                    'meta'              => empty($meta) ? null : $meta,
                ]);
            }
        });
    }

    private function str($v, int $max): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return mb_substr((string) $v, 0, $max);
    }

    private function intOrNull($v): ?int
    {
        return (is_int($v) || (is_string($v) && $v !== '' && ctype_digit($v))) ? (int) $v : null;
    }

    /** Estado que a UI (A3) vai consumir. Deriva 'never_analyzed' e 'outdated' (stale). */
    private function stateView(SourceDoc $doc, ?string $currentBlob, ?SourceDocQualityAnalysis $rec): array
    {
        $state = 'never_analyzed';
        $analysis = null;
        if ($rec) {
            $stale = ! $rec->matchesBlob($currentBlob);
            $state = $rec->status;
            if ($rec->status === SourceDocQualityAnalysis::STATUS_COMPLETED && $stale) {
                $state = 'outdated';
            }
            $analysis = [
                'id'                => $rec->id,
                'status'            => $rec->status,
                'source_blob_sha'   => $rec->source_blob_sha,
                'external_job_id'   => $rec->external_job_id,
                'score'             => $rec->score,
                'grade'             => $rec->grade,
                'risk'              => $rec->risk,
                'counts'            => [
                    'critical'        => $rec->n_critical,
                    'warnings'        => $rec->n_warnings,
                    'recommendations' => $rec->n_recommendations,
                    'total'           => $rec->n_findings,
                ],
                'engine'            => $rec->engine,
                'engine_version'    => $rec->engine_version,
                'rules_version'     => $rec->rules_version,
                'requested_at'      => optional($rec->requested_at)->toIso8601String(),
                'started_at'        => optional($rec->started_at)->toIso8601String(),
                'completed_at'      => optional($rec->completed_at)->toIso8601String(),
                'failed_at'         => optional($rec->failed_at)->toIso8601String(),
                'error_code'        => $rec->error_code,
                'error_message'     => $rec->error_message,
                'stale'             => $stale,
            ];
        }
        return [
            'state'            => $state,
            'source_doc_id'    => $doc->id,
            'current_blob_sha' => $currentBlob,
            'analysis'         => $analysis,
        ];
    }

    private function audit(SourceDoc $doc, string $status, array $params, ?int $userId): void
    {
        SourceDocActionLog::create([
            'source_doc_id' => $doc->id,
            'version_id'    => $doc->current_version_id,
            'action'        => 'quality_run',
            'actor_user_id' => $userId,
            'status'        => $status,
            'params'        => SourceDocActionLog::sanitize($params),
        ]);
    }

    private function ts($value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function gitBlobSha(string $content): string
    {
        return sha1('blob ' . strlen($content) . "\0" . $content);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->getCode() === '23505') // pg unique_violation
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
