<?php

namespace App\Connector;

use App\Connector\Compile\CompileAdapter;
use App\Connector\Compile\FixtureCompileAdapter;
use App\Connector\Compile\LiveCompileAdapter;
use App\Connector\Compile\SimulatedCompileAdapter;
use App\Models\ArtifactCandidate;
use App\Models\CompileContext;
use App\Models\CompileExecution;
use App\Models\CompileRequest;
use App\Models\ConnectorEvent;
use App\Models\EnvEnvironment;
use App\SourceCode\SourceRepoResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * C6 — CompileService: orquestra SOURCE → COMPILE REQUEST → EXECUTION → ARTIFACT CANDIDATE. Compile PRODUZ
 * artefato; NÃO publica RPO (o handoff ao C5 é GOVERNADO e separado; C6 nunca promove). Três modos explícitos
 * SEM fallback silencioso. Autoridade server-side (customer sempre do ambiente; fonte via SourceRepoResolver,
 * anti-IDOR). Zero bytes/paths/secrets/log bruto persistidos (sanitização defensiva).
 */
class CompileService
{
    public function __construct(
        private SourceRepoResolver $repos,
        private RpoRegistryService $rpo,
    ) {
    }

    // ── Timeline C1 (mesma projeção; sem segunda timeline) ─────────────────────
    private function emit(int $envId, string $type, ?string $detail, array $meta): void
    {
        ConnectorEvent::create([
            'environment_id' => $envId, 'appserver_ref' => null, 'event_type' => $type,
            'outcome' => 'info', 'detail' => $detail, 'meta' => $this->sanitize($meta), 'occurred_at' => now(),
        ]);
    }

    // ── Sanitização defensiva: nada de path/secret/host cruza a fronteira, mesmo se um adapter vazar. ──
    private const FORBIDDEN = '#(/[A-Za-z0-9_.\-]+){2,}|[A-Za-z]:\\\\[^\s]*|\b(?:password|secret|token|api[_-]?key|private[ _]?key|connection ?string)\b\s*[:=]?\s*\S*|BEGIN [A-Z ]+PRIVATE KEY|\bEnv[A-Z]\w*#i';

    private function safeString(string $s): string
    {
        return (string) preg_replace(self::FORBIDDEN, '[redacted]', mb_substr($s, 0, 500));
    }

    private function sanitize(array $a): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $out[$k] = $this->sanitize($v);
            } elseif (is_string($v)) {
                $out[$k] = $this->safeString($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    // ── Resolução de adapter por modo (container-bindable p/ testes). ──────────
    public function adapterFor(string $mode): CompileAdapter
    {
        return match ($mode) {
            CompileRequest::MODE_FIXTURE => app(FixtureCompileAdapter::class),
            CompileRequest::MODE_SIMULATED => app(SimulatedCompileAdapter::class),
            CompileRequest::MODE_LIVE => app(LiveCompileAdapter::class),
            default => throw new \InvalidArgumentException('unknown_mode'),
        };
    }

    /** Modo que a REQUEST pode pedir (fail-closed). fixture só com flag; simulated/live via allowlist. */
    private function modeRequestable(string $mode): bool
    {
        if ($mode === CompileRequest::MODE_FIXTURE) {
            return (bool) config('connector.compile.allow_fixture', false);
        }
        $modes = (array) config('connector.compile.executable_modes', ['simulated']);
        return in_array($mode, $modes, true);
    }

    // ── Criação da REQUEST (autoridade env+fonte; sem execução). ───────────────
    public function createRequest(EnvEnvironment $env, array $data, int $userId): array
    {
        $mode = (string) ($data['execution_mode'] ?? '');
        if (! in_array($mode, [CompileRequest::MODE_FIXTURE, CompileRequest::MODE_SIMULATED, CompileRequest::MODE_LIVE], true)) {
            return ['ok' => false, 'error' => 'invalid_mode', 'status' => 422];
        }
        if (! $this->modeRequestable($mode)) {
            return ['ok' => false, 'error' => 'mode_not_executable', 'status' => 422]; // fail-closed, sem fallback
        }
        $langs = (array) config('connector.compile.supported_languages', ['advpl', 'tlpp']);
        if (! in_array(strtolower((string) ($data['language'] ?? '')), $langs, true)) {
            return ['ok' => false, 'error' => 'unsupported_language', 'status' => 422];
        }
        // Autoridade da FONTE (anti-IDOR): repository = owner/repo; valida customer→repo→path.
        [$owner, $repo] = array_pad(explode('/', (string) ($data['repository'] ?? ''), 2), 2, null);
        if (! $owner || ! $repo) {
            return ['ok' => false, 'error' => 'invalid_repository', 'status' => 422];
        }
        $customer = $env->customer;
        if (! $customer) {
            return ['ok' => false, 'error' => 'environment_without_customer', 'status' => 404];
        }
        try {
            $this->repos->assertAuthorized($customer, $owner, $repo, (string) $data['source_path']);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'source_not_authorized', 'status' => 404]; // não vaza existência
        }

        $req = DB::transaction(function () use ($env, $data, $userId, $mode) {
            $r = CompileRequest::create([
                'customer_id' => $env->customer_id, 'environment_id' => $env->id,
                'repository' => $data['repository'], 'branch' => $data['branch'], 'source_path' => $data['source_path'],
                'source_commit_sha' => $data['source_commit_sha'] ?? null, 'source_blob_sha' => $data['source_blob_sha'],
                'language' => strtolower((string) $data['language']), 'target' => $data['target'] ?? null,
                'execution_mode' => $mode, 'classification' => $data['classification'] ?? null,
                'status' => CompileRequest::ST_OPEN, 'correlation_id' => (string) Str::uuid(),
                'requested_by' => $userId, 'requested_at' => now(),
            ]);
            CompileContext::create(['compile_request_id' => $r->id, 'captured_at' => null]); // preenchido na execução
            return $r;
        });
        $this->emit((int) $env->id, 'compile.requested', 'Compilação solicitada', [
            'request_id' => $req->id, 'correlation_id' => $req->correlation_id, 'mode' => $mode,
            'language' => $req->language, 'blob' => substr((string) $req->source_blob_sha, 0, 12),
        ]);
        return ['ok' => true, 'request' => $req];
    }

    // ── Execução (state machine própria; at-most-once por execution_id). ───────
    public function execute(CompileRequest $req, int $userId): array
    {
        if ($req->status === CompileRequest::ST_CANCELED) {
            return ['ok' => false, 'error' => 'request_canceled', 'status' => 409];
        }
        if ($req->executions()->whereNotIn('status', CompileExecution::TERMINAL)->exists()) {
            return ['ok' => false, 'error' => 'execution_in_progress', 'status' => 409]; // sem retry concorrente
        }

        $adapter = $this->adapterFor($req->execution_mode);
        $avail = $adapter->availability($req);

        $exec = CompileExecution::create([
            'compile_request_id' => $req->id, 'execution_id' => (string) Str::uuid(),
            'execution_mode' => $req->execution_mode, 'adapter' => class_basename($adapter),
            'status' => CompileExecution::ST_PENDING,
            'deadline_at' => now()->addSeconds((int) config('connector.compile.operational_deadline', 600)),
        ]);

        // Indisponível → BLOQUEIO explícito, sem fake, sem fallback. live_unavailable = "aguardando conector TOTVS".
        if (! ($avail['available'] ?? false)) {
            $reason = (string) ($avail['reason'] ?? 'unavailable');
            $exec->update(['status' => CompileExecution::ST_FAILED, 'outcome' => CompileExecution::ST_FAILED, 'error' => $reason, 'finished_at' => now()]);
            $req->update(['status' => CompileRequest::ST_FAILED]);
            $this->emit((int) $req->environment_id, 'compile.failed', 'Compilação bloqueada (modo indisponível)', [
                'request_id' => $req->id, 'execution_id' => $exec->execution_id, 'mode' => $req->execution_mode, 'reason' => $reason,
            ]);
            return ['ok' => false, 'blocked' => true, 'error' => $reason, 'execution' => $exec->fresh()];
        }

        // Disponível → roda a máquina. fixture/simulated: inline. (live dispatcharia ao agente — não exercido enquanto unavailable.)
        $req->update(['status' => CompileRequest::ST_EXECUTING]);
        $exec->update(['status' => CompileExecution::ST_CLAIMED, 'claimed_at' => now()]);
        $this->emit((int) $req->environment_id, 'compile.claimed', null, ['request_id' => $req->id, 'execution_id' => $exec->execution_id, 'mode' => $req->execution_mode]);
        $exec->update(['status' => CompileExecution::ST_RUNNING, 'started_at' => now()]);
        $this->emit((int) $req->environment_id, 'compile.started', null, ['request_id' => $req->id, 'execution_id' => $exec->execution_id]);

        $out = $adapter->compile($req, $exec);
        $outcome = (string) ($out['outcome'] ?? CompileExecution::ST_UNKNOWN);

        // Persiste o CONTEXTO observado (fatores) — sem fórmula/hash.
        if (! empty($out['context']) && is_array($out['context'])) {
            $c = $out['context'];
            $ctx = $req->context()->first() ?: new CompileContext(['compile_request_id' => $req->id]);
            $ctx->fill([
                'compile_request_id' => $req->id,
                'compiler_identity' => $c['compiler_identity'] ?? null, 'compiler_version' => $c['compiler_version'] ?? null,
                'compiler_build' => $c['compiler_build'] ?? null, 'compiler_patch' => $c['compiler_patch'] ?? null,
                'target_runtime' => $c['target_runtime'] ?? null, 'factors' => $this->sanitize((array) ($c['factors'] ?? [])),
                'captured_at' => now(),
            ])->save();
        }

        $exec->update([
            'status' => $outcome, 'outcome' => $outcome, 'finished_at' => now(),
            'diagnostics' => $this->sanitize((array) ($out['diagnostics'] ?? [])), 'error' => $out['error'] ?? null,
        ]);

        if ($outcome === CompileExecution::ST_SUCCEEDED) {
            $art = $out['artifact'] ?? null;
            if (! is_array($art) || empty($art['digest'])) {
                // succeeded sem artifact é INCONSISTENTE → trata como unknown (defesa; nunca cria candidato vazio).
                $exec->update(['status' => CompileExecution::ST_UNKNOWN, 'outcome' => CompileExecution::ST_UNKNOWN, 'error' => 'succeeded_without_artifact']);
                $req->update(['status' => CompileRequest::ST_FAILED]);
                $this->emit((int) $req->environment_id, 'compile.unknown', null, ['request_id' => $req->id, 'execution_id' => $exec->execution_id, 'reason' => 'succeeded_without_artifact']);
                return ['ok' => false, 'error' => 'succeeded_without_artifact', 'execution' => $exec->fresh()];
            }
            $cand = ArtifactCandidate::create([
                'compile_execution_id' => $exec->id, 'compile_request_id' => $req->id,
                'environment_id' => $req->environment_id, 'customer_id' => $req->customer_id,
                'artifact_digest' => $art['digest'], 'artifact_unit' => $art['unit'] ?? ArtifactCandidate::UNIT_UNKNOWN,
                'size_bytes' => $art['size_bytes'] ?? null, 'artifact_metadata' => $this->sanitize((array) ($art['metadata'] ?? [])),
                'provenance' => [
                    'repository' => $req->repository, 'branch' => $req->branch, 'source_path' => $req->source_path,
                    'source_commit_sha' => $req->source_commit_sha, 'source_blob_sha' => $req->source_blob_sha,
                    'compile_execution_id' => $exec->execution_id, 'compile_context_id' => optional($req->context()->first())->id,
                    'execution_mode' => $req->execution_mode,
                ],
                'classification' => $req->classification, 'handoff_status' => ArtifactCandidate::HANDOFF_NONE, 'created_by' => $userId,
            ]);
            $req->update(['status' => CompileRequest::ST_COMPLETED]);
            $this->emit((int) $req->environment_id, 'compile.succeeded', null, ['request_id' => $req->id, 'execution_id' => $exec->execution_id]);
            $this->emit((int) $req->environment_id, 'artifact.created', 'Artefato candidato gerado', [
                'request_id' => $req->id, 'execution_id' => $exec->execution_id, 'candidate_id' => $cand->id,
                'digest' => substr((string) $art['digest'], 0, 12), 'unit' => $cand->artifact_unit, 'mode' => $req->execution_mode,
            ]);
            return ['ok' => true, 'execution' => $exec->fresh(), 'candidate' => $cand];
        }

        // failed | timed_out | unknown → NENHUM artifact.
        $req->update(['status' => CompileRequest::ST_FAILED]);
        $this->emit((int) $req->environment_id, 'compile.' . $outcome, null, ['request_id' => $req->id, 'execution_id' => $exec->execution_id, 'reason' => $out['error'] ?? $outcome]);
        return ['ok' => true, 'execution' => $exec->fresh(), 'candidate' => null, 'outcome' => $outcome];
    }

    // ── Cancelamento (request aberta / execução ainda não terminal). ───────────
    public function cancel(CompileRequest $req, int $userId): array
    {
        if (in_array($req->status, [CompileRequest::ST_COMPLETED, CompileRequest::ST_CANCELED], true)) {
            return ['ok' => false, 'error' => 'not_cancelable', 'status' => 409];
        }
        $req->executions()->whereNotIn('status', CompileExecution::TERMINAL)
            ->update(['status' => CompileExecution::ST_CANCELLED, 'outcome' => CompileExecution::ST_CANCELLED, 'finished_at' => now()]);
        $req->update(['status' => CompileRequest::ST_CANCELED]);
        $this->emit((int) $req->environment_id, 'compile.cancelled', 'Compilação cancelada', ['request_id' => $req->id]);
        return ['ok' => true];
    }

    // ── C6.7 — HANDOFF GOVERNADO ao registry C5. Entrega digest+proveniência ao register (autoridade do C5).
    //    C6 NUNCA promove/qualifica: quem registra é o RpoRegistryService (C5); qualify/promote seguem no C5.
    public function handoff(ArtifactCandidate $cand, int $userId): array
    {
        if ($cand->handoff_status === ArtifactCandidate::HANDOFF_REGISTERED) {
            return ['ok' => false, 'error' => 'already_registered', 'status' => 409];
        }
        // Compatibilidade derivada do CONTEXTO observado (appserver_versions do compiler_version, se houver).
        $ctx = optional($cand->request()->first())->context()->first();
        $compat = [
            'source' => 'c6_compile',
            'appserver_versions' => ($ctx && $ctx->compiler_version) ? [$ctx->compiler_version] : [],
        ];
        $prov = 'C6-COMPILE exec=' . substr((string) ($cand->provenance['compile_execution_id'] ?? ''), 0, 12)
            . ' blob=' . substr((string) ($cand->provenance['source_blob_sha'] ?? ''), 0, 12)
            . ' repo=' . mb_substr((string) ($cand->provenance['repository'] ?? ''), 0, 120);

        // Marca a intenção (auditável) ANTES de invocar o C5.
        $cand->update(['handoff_status' => ArtifactCandidate::HANDOFF_REQUESTED]);
        $this->emit((int) $cand->environment_id, 'artifact.handoff_requested', 'Artefato enviado para qualificação (registry C5)', [
            'candidate_id' => $cand->id, 'digest' => substr((string) $cand->artifact_digest, 0, 12),
            'execution_id' => $cand->provenance['compile_execution_id'] ?? null,
        ]);

        // Autoridade do C5: o register é executado pelo RpoRegistryService (não por C6). NUNCA promove.
        $res = $this->rpo->register((int) $cand->environment_id, $cand->customer_id, [
            'hash' => $cand->artifact_digest, 'version' => null,
            'provenance' => $prov, 'compatibility' => $compat,
            'classification' => $cand->classification, 'source_identity' => null, // NUNCA path/bytes
        ], $userId);

        if (! ($res['ok'] ?? false)) {
            return ['ok' => false, 'error' => $res['error'] ?? 'register_failed', 'status' => 422]; // fica em 'requested' (tentável)
        }
        $cand->update(['handoff_status' => ArtifactCandidate::HANDOFF_REGISTERED, 'rpo_artifact_id' => $res['artifact']->id]);
        return ['ok' => true, 'rpo_artifact_id' => $res['artifact']->id];
    }
}
