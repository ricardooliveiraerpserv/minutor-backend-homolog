<?php

namespace App\Http\Controllers;

use App\Connector\CompileService;
use App\Models\ArtifactCandidate;
use App\Models\CompileExecution;
use App\Models\CompileRequest;
use App\Models\ConnectorEnvironmentState;
use App\Models\EnvEnvironment;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * C6 — COMPILE. Produz ARTEFATO candidato; NÃO publica RPO (nenhum endpoint promove). Escopo por customer_id
 * do AMBIENTE (anti-IDOR 404). Zero bytes/paths/secrets no payload. Três modos explícitos (fixture|simulated|
 * live) SEM fallback: quando live não está conectado, a resposta é explícita ("aguardando conector TOTVS").
 */
class CompileController extends Controller
{
    public function __construct(
        private CompileService $svc,
        private SourceDocCustomerScope $scope,
    ) {
    }

    private function env(Request $r, int $id): ?EnvEnvironment
    {
        $env = EnvEnvironment::query()->whereKey($id)->first(['id', 'customer_id', 'type']);
        return ($env && $this->scope->canAccessCustomerId($r->user(), (int) $env->customer_id)) ? $env : null;
    }

    private function request(Request $r, int $id): ?CompileRequest
    {
        $x = CompileRequest::find($id);
        return ($x && $this->scope->canAccessCustomerId($r->user(), (int) $x->customer_id)) ? $x : null;
    }

    private function candidate(Request $r, int $id): ?ArtifactCandidate
    {
        $x = ArtifactCandidate::find($id);
        return ($x && $this->scope->canAccessCustomerId($r->user(), (int) $x->customer_id)) ? $x : null;
    }

    // ── Capability / modos disponíveis (feed dos badges honestos no FE). ───────
    public function capability(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $state = ConnectorEnvironmentState::where('environment_id', $env->id)->first();
        $cap = is_array($state?->compile_capability ?? null) ? $state->compile_capability : null;
        // Disponibilidade do live SEM expor nada sensível (só o motivo).
        $liveProbe = $this->svc->adapterFor(CompileRequest::MODE_LIVE)->availability(new CompileRequest(['environment_id' => $env->id]));
        return response()->json(['data' => [
            'executable_modes' => array_values((array) config('connector.compile.executable_modes', ['simulated'])),
            'allow_fixture' => (bool) config('connector.compile.allow_fixture', false),
            'supported_languages' => array_values((array) config('connector.compile.supported_languages', ['advpl', 'tlpp'])),
            'live' => ['available' => (bool) ($liveProbe['available'] ?? false), 'reason' => $liveProbe['reason'] ?? null],
            'capability_declared' => $cap ? ['name' => $cap['name'] ?? null, 'contract_version' => $cap['contract_version'] ?? null] : null,
        ]]);
    }

    // ── Criar REQUEST (perm compile.request). ──────────────────────────────────
    public function create(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $data = $r->validate([
            'repository' => 'required|string|max:200',
            'branch' => 'required|string|max:200',
            'source_path' => 'required|string|max:500',
            'source_commit_sha' => 'nullable|string|size:64',
            'source_blob_sha' => 'required|string|size:64',
            'language' => 'required|string|max:20',
            'target' => 'nullable|string|max:80',
            'execution_mode' => 'required|in:fixture,simulated,live',
            'classification' => 'nullable|in:test,demo,operational',
        ]);
        $res = $this->svc->createRequest($env, $data, (int) $r->user()->id);
        if (! ($res['ok'] ?? false)) {
            return response()->json(['message' => $this->errMsg($res['error']), 'error' => $res['error']], (int) ($res['status'] ?? 422));
        }
        return response()->json(['data' => $this->requestView($res['request'])], 201);
    }

    // ── Executar (perm compile.request). ────────────────────────────────────────
    public function execute(Request $r, int $id): JsonResponse
    {
        $req = $this->request($r, $id);
        if (! $req) { return response()->json(['message' => 'Compilação não encontrada.'], 404); }
        $res = $this->svc->execute($req, (int) $r->user()->id);
        if (($res['blocked'] ?? false) === true) {
            // Estado HONESTO, não erro de cliente: live/fixture indisponível. Sem fake, sem fallback.
            return response()->json([
                'data' => $this->executionView($res['execution']),
                'blocked' => true, 'reason' => $res['error'],
                'message' => $this->blockedMsg($res['error']),
            ], 200);
        }
        if (! ($res['ok'] ?? false)) {
            return response()->json(['message' => $this->errMsg($res['error']), 'error' => $res['error']], (int) ($res['status'] ?? 422));
        }
        return response()->json(['data' => [
            'execution' => $this->executionView($res['execution']),
            'candidate' => isset($res['candidate']) && $res['candidate'] ? $this->candidateView($res['candidate']) : null,
        ]]);
    }

    // ── Cancelar (perm compile.request). ────────────────────────────────────────
    public function cancel(Request $r, int $id): JsonResponse
    {
        $req = $this->request($r, $id);
        if (! $req) { return response()->json(['message' => 'Compilação não encontrada.'], 404); }
        $res = $this->svc->cancel($req, (int) $r->user()->id);
        if (! ($res['ok'] ?? false)) {
            return response()->json(['message' => $this->errMsg($res['error']), 'error' => $res['error']], (int) ($res['status'] ?? 409));
        }
        return response()->json(['data' => ['ok' => true]]);
    }

    // ── Listar (perm compile.view). ─────────────────────────────────────────────
    public function index(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $rows = CompileRequest::where('environment_id', $env->id)->orderByDesc('id')->limit(200)->get()
            ->map(fn ($x) => $this->requestView($x))->all();
        return response()->json(['data' => ['requests' => $rows]]);
    }

    // ── Detalhe (perm compile.view). ────────────────────────────────────────────
    public function show(Request $r, int $id): JsonResponse
    {
        $req = $this->request($r, $id);
        if (! $req) { return response()->json(['message' => 'Compilação não encontrada.'], 404); }
        $execs = $req->executions()->orderBy('id')->get()->map(fn ($e) => $this->executionView($e))->all();
        $cands = $req->candidates()->orderByDesc('id')->get()->map(fn ($c) => $this->candidateView($c))->all();
        return response()->json(['data' => [
            'request' => $this->requestView($req),
            'context' => $this->contextView($req->context()->first()),
            'executions' => $execs,
            'candidates' => $cands,
        ]]);
    }

    // ── C6.7 — Handoff GOVERNADO ao registry C5 (perm compile.handoff). NÃO publica/promove. ──
    public function handoff(Request $r, int $id): JsonResponse
    {
        $cand = $this->candidate($r, $id);
        if (! $cand) { return response()->json(['message' => 'Artefato não encontrado.'], 404); }
        $res = $this->svc->handoff($cand, (int) $r->user()->id);
        if (! ($res['ok'] ?? false)) {
            return response()->json(['message' => $this->errMsg($res['error']), 'error' => $res['error']], (int) ($res['status'] ?? 422));
        }
        return response()->json(['data' => [
            'ok' => true, 'rpo_artifact_id' => $res['rpo_artifact_id'] ?? null,
            'candidate' => $this->candidateView($cand->fresh()),
            'message' => 'Artefato registrado no C5 para qualificação. Não publicado — publicação segue no C5.',
        ]]);
    }

    // ── Views (allowlist de projeção — DENY BY DEFAULT; zero bytes/path/secret). ─
    private function requestView(CompileRequest $x): array
    {
        return [
            'id' => $x->id, 'environment_id' => $x->environment_id, 'customer_id' => $x->customer_id,
            'repository' => $x->repository, 'branch' => $x->branch, 'source_path' => $x->source_path,
            'source_commit_sha' => $x->source_commit_sha, 'source_blob_sha' => $x->source_blob_sha,
            'language' => $x->language, 'target' => $x->target, 'execution_mode' => $x->execution_mode,
            'classification' => $x->classification, 'status' => $x->status, 'correlation_id' => $x->correlation_id,
            'requested_at' => optional($x->requested_at)->toIso8601String(),
        ];
    }

    private function contextView(?\App\Models\CompileContext $c): ?array
    {
        if (! $c) { return null; }
        return [
            'compiler_identity' => $c->compiler_identity, 'compiler_version' => $c->compiler_version,
            'compiler_build' => $c->compiler_build, 'compiler_patch' => $c->compiler_patch,
            'target_runtime' => $c->target_runtime, 'factors' => $c->factors,
            'captured_at' => optional($c->captured_at)->toIso8601String(),
        ];
    }

    private function executionView(CompileExecution $e): array
    {
        return [
            'id' => $e->id, 'execution_id' => $e->execution_id, 'execution_mode' => $e->execution_mode,
            'adapter' => $e->adapter, 'status' => $e->status, 'outcome' => $e->outcome, 'error' => $e->error,
            'diagnostics' => $e->diagnostics, // SANITIZADO (SAFE only)
            'claimed_at' => optional($e->claimed_at)->toIso8601String(),
            'started_at' => optional($e->started_at)->toIso8601String(),
            'finished_at' => optional($e->finished_at)->toIso8601String(),
        ];
    }

    private function candidateView(ArtifactCandidate $c): array
    {
        return [
            'id' => $c->id, 'compile_execution_id' => $c->compile_execution_id,
            'artifact_digest' => $c->artifact_digest, 'artifact_unit' => $c->artifact_unit,
            'size_bytes' => $c->size_bytes, 'artifact_metadata' => $c->artifact_metadata,
            'provenance' => $c->provenance, 'classification' => $c->classification,
            'handoff_status' => $c->handoff_status, 'rpo_artifact_id' => $c->rpo_artifact_id,
            // Labels HONESTOS (Compilado ≠ Publicado, Artefato ≠ Known-good).
            'is_known_good' => false, 'is_published' => false,
        ];
    }

    private function errMsg(string $e): string
    {
        return match ($e) {
            'invalid_mode', 'mode_not_executable' => 'Modo de compilação indisponível.',
            'unsupported_language' => 'Linguagem não suportada.',
            'invalid_repository' => 'Repositório inválido.',
            'source_not_authorized' => 'Fonte não encontrada.',
            'environment_without_customer' => 'Ambiente sem cliente.',
            'execution_in_progress' => 'Já existe uma execução em andamento.',
            'request_canceled', 'not_cancelable' => 'Operação não permitida neste estado.',
            'already_registered' => 'Artefato já registrado no C5.',
            'register_failed', 'provenance_and_compatibility_required', 'invalid_hash' => 'Falha ao registrar no C5.',
            default => 'Não foi possível processar a compilação.',
        };
    }

    private function blockedMsg(string $reason): string
    {
        return match ($reason) {
            'live_unavailable' => 'Compilação real aguardando conector TOTVS.',
            'compile_capability_absent' => 'Ambiente não declarou a capability de compilação.',
            'compile_contract_unsupported' => 'Versão de contrato de compilação não suportada.',
            'fixture_disabled' => 'Modo fixture desabilitado neste ambiente.',
            'simulated_not_executable' => 'Modo simulado não habilitado neste ambiente.',
            default => 'Compilação indisponível no momento.',
        };
    }
}
