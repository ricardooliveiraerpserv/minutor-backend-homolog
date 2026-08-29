<?php

namespace App\Http\Controllers;

use App\Connector\PatchExecutionService;
use App\Connector\PatchService;
use App\Models\EnvEnvironment;
use App\Models\PatchArtifactCandidate;
use App\Models\PatchExecution;
use App\Models\PatchInput;
use App\Models\PatchRequest;
use App\Services\PermissionService;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PATCH P1 — FUNDAÇÃO. Cadastro de PatchInput + PatchRequest (base_rpo_hash congelado, lote ordenado). P1 NÃO
 * executa/aplica/registra no C5. Escopo por customer do ambiente (anti-IDOR 404). Zero bytes/path/PTM/secret.
 */
class PatchController extends Controller
{
    public function __construct(
        private PatchService $svc,
        private PatchExecutionService $exec,
        private SourceDocCustomerScope $scope,
    ) {
    }

    private function hasPerm($user, string $key): bool
    {
        $perms = PermissionService::for($user);
        return in_array('*', $perms, true) || in_array($key, $perms, true);
    }

    /** Execução acessível ao usuário admin/escopo (anti-IDOR via customer do request). */
    private function execFor(Request $r, int $id): ?PatchExecution
    {
        $ex = PatchExecution::find($id);
        if (! $ex) { return null; }
        $req = PatchRequest::find($ex->patch_request_id);
        return ($req && $this->scope->canAccessCustomerId($r->user(), (int) $req->customer_id)) ? $ex : null;
    }

    /** Execução pertencente ao AMBIENTE do agente (anti cross-env). */
    private function agentExec($agent, int $id): ?PatchExecution
    {
        $ex = PatchExecution::find($id);
        if (! $ex) { return null; }
        $envId = (int) PatchRequest::whereKey($ex->patch_request_id)->value('environment_id');
        return ($agent && $envId === (int) $agent->environment_id) ? $ex : null;
    }

    private function env(Request $r, int $id): ?EnvEnvironment
    {
        $env = EnvEnvironment::query()->whereKey($id)->first(['id', 'customer_id', 'type']);
        return ($env && $this->scope->canAccessCustomerId($r->user(), (int) $env->customer_id)) ? $env : null;
    }

    private function req(Request $r, int $id): ?PatchRequest
    {
        $x = PatchRequest::find($id);
        return ($x && $this->scope->canAccessCustomerId($r->user(), (int) $x->customer_id)) ? $x : null;
    }

    // GET /prosight/environments/{environmentId}/patch/availability
    public function availability(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        return response()->json(['data' => $this->svc->availability($env)]);
    }

    // GET /prosight/environments/{environmentId}/patch/inputs
    public function inputs(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $rows = PatchInput::where('environment_id', $env->id)->orderByDesc('id')->limit(200)->get()
            ->map(fn ($x) => $this->inputView($x))->all();
        return response()->json(['data' => ['inputs' => $rows]]);
    }

    // POST /prosight/environments/{environmentId}/patch/inputs  (perm patch.request)
    public function createInput(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $data = $r->validate([
            'patch_id' => 'required|string|max:120',
            'source_ref' => 'nullable|string|max:200',
            'digest' => 'required|string|size:64',
            'provenance' => 'nullable|string|max:300',
            'version' => 'nullable|string|max:60',
            'release' => 'nullable|string|max:60',
            'compatibility' => 'nullable|array',
            'classification' => 'nullable|in:test,demo,operational',
        ]);
        $res = $this->svc->createInput($env, $data, (int) $r->user()->id);
        if (! ($res['ok'] ?? false)) {
            return response()->json(['error' => $res['error'], 'message' => $this->msg($res['error'])], (int) ($res['status'] ?? 422));
        }
        return response()->json(['data' => $this->inputView($res['input'])], 201);
    }

    // GET /prosight/environments/{environmentId}/patch/requests
    public function requests(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $rows = PatchRequest::where('environment_id', $env->id)->orderByDesc('id')->limit(200)->get()
            ->map(fn ($x) => $this->requestView($x))->all();
        return response()->json(['data' => ['requests' => $rows]]);
    }

    // POST /prosight/environments/{environmentId}/patch/requests  (perm patch.request)
    public function createRequest(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $data = $r->validate([
            'base_rpo_hash' => 'required|string|size:64',
            'execution_mode' => 'required|in:fixture,simulated,live',
            'workspace_unit_id' => 'nullable|string|max:80|regex:/^[A-Za-z0-9_.:-]{1,80}$/',
            'patch_input_ids' => 'required|array|min:1|max:100',
            'patch_input_ids.*' => 'integer',
            'classification' => 'nullable|in:test,demo,operational',
        ]);
        $res = $this->svc->createRequest($env, $data, (int) $r->user()->id);
        if (! ($res['ok'] ?? false)) {
            return response()->json(['error' => $res['error'], 'message' => $this->msg($res['error'])], (int) ($res['status'] ?? 422));
        }
        return response()->json(['data' => $this->requestView($res['request']->fresh())], 201);
    }

    // GET /prosight/patch/requests/{id}
    public function show(Request $r, int $id): JsonResponse
    {
        $req = $this->req($r, $id);
        if (! $req) { return response()->json(['message' => 'Request não encontrada.'], 404); }
        return response()->json(['data' => [
            'request' => $this->requestView($req),
            'items' => $req->items()->get()->map(fn ($i) => ['batch_order' => $i->batch_order, 'patch_input_id' => $i->patch_input_id, 'item_digest' => $i->item_digest])->all(),
            // P1: sem execuções/candidatos (não executa). Contrato preparado p/ P2.
            'note' => 'P1 — fundação: sem execução física, sem candidate, sem registro no C5.',
        ]]);
    }

    // ── EXECUÇÃO (P2) — máquina governada. SIMULADO: nenhuma física TOTVS; Live indisponível. ──

    // POST /prosight/patch/requests/{id}/execute  (perm patch.execute)  → dispatch (adquire lock fenced + pin)
    public function execute(Request $r, int $id): JsonResponse
    {
        $req = $this->req($r, $id);
        if (! $req) { return response()->json(['message' => 'Request não encontrada.'], 404); }
        if (! $this->hasPerm($r->user(), 'prosight.operations.patch.execute')) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        // Fail-closed: Live NUNCA executa em P2 (sem física TOTVS). fixture/simulated conforme availability.
        if ($req->execution_mode === PatchRequest::MODE_LIVE) {
            return response()->json(['error' => 'live_unavailable', 'message' => 'Execução real de patch ainda não disponível (aguardando conector TOTVS).'], 422);
        }
        $avail = $this->svc->availability(EnvEnvironment::find($req->environment_id));
        if (! ($avail[$req->execution_mode]['available'] ?? false)) {
            return response()->json(['error' => 'mode_not_executable'], 422);
        }
        $res = $this->exec->dispatch($req, $req->execution_mode, (int) $r->user()->id);
        if (! ($res['ok'] ?? false)) {
            return response()->json(['error' => $res['error'], 'message' => $this->msg($res['error'])], (int) ($res['status'] ?? 422));
        }
        return response()->json(['data' => $this->executionView($res['execution'])], 201);
    }

    // POST /prosight/patch/executions/{id}/reconcile  (perm patch.execute) — perda de ACK/resposta → causal
    public function reconcile(Request $r, int $id): JsonResponse
    {
        $ex = $this->execFor($r, $id);
        if (! $ex) { return response()->json(['message' => 'Execução não encontrada.'], 404); }
        if (! $this->hasPerm($r->user(), 'prosight.operations.patch.execute')) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $data = $r->validate(['observed_candidate_digest' => 'nullable|string|size:64']);
        $res = $this->exec->reconcile($ex, $data['observed_candidate_digest'] ?? null, (int) $r->user()->id);
        return response()->json(['data' => $this->executionView($res['execution']), 'outcome' => $res['outcome']]);
    }

    // POST /prosight/patch/executions/{id}/resolve  (perm patch.execute) — libera workspace de INDETERMINATE
    public function resolve(Request $r, int $id): JsonResponse
    {
        $ex = $this->execFor($r, $id);
        if (! $ex) { return response()->json(['message' => 'Execução não encontrada.'], 404); }
        if (! $this->hasPerm($r->user(), 'prosight.operations.patch.execute')) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $res = $this->exec->resolve($ex, (int) $r->user()->id);
        if (! ($res['ok'] ?? false)) {
            return response()->json(['error' => $res['error']], (int) ($res['status'] ?? 409));
        }
        return response()->json(['data' => $this->executionView($res['execution'])]);
    }

    // GET /prosight/patch/executions/{id}  (perm patch.view)
    public function showExecution(Request $r, int $id): JsonResponse
    {
        $ex = $this->execFor($r, $id);
        if (! $ex) { return response()->json(['message' => 'Execução não encontrada.'], 404); }
        return response()->json(['data' => [
            'execution' => $this->executionView($ex),
            'items' => $ex->items()->get()->map(fn ($i) => ['batch_order' => $i->batch_order, 'status' => $i->status,
                'started_at' => optional($i->started_at)->toIso8601String(), 'committed_at' => optional($i->committed_at)->toIso8601String()])->all(),
        ]]);
    }

    // ── P3 — CANDIDATE + HANDOFF ao C5. Boundary: termina em C5 REGISTERED (nunca qualifica/promove/publica). ──

    private function candFor(Request $r, int $id): ?PatchArtifactCandidate
    {
        $c = PatchArtifactCandidate::find($id);
        return ($c && $this->scope->canAccessCustomerId($r->user(), (int) $c->customer_id)) ? $c : null;
    }

    // GET /prosight/environments/{environmentId}/patch/candidates  (perm patch.view)
    public function candidates(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $rows = PatchArtifactCandidate::where('environment_id', $env->id)->orderByDesc('id')->limit(200)->get()
            ->map(fn ($c) => $this->candidateView($c))->all();
        return response()->json(['data' => ['candidates' => $rows]]);
    }

    // GET /prosight/patch/candidates/{id}  (perm patch.view)
    public function showCandidate(Request $r, int $id): JsonResponse
    {
        $c = $this->candFor($r, $id);
        if (! $c) { return response()->json(['message' => 'Candidato não encontrado.'], 404); }
        return response()->json(['data' => ['candidate' => $this->candidateView($c, true)]]);
    }

    // POST /prosight/patch/candidates/{id}/handoff  (perm patch.register) — ação EXPLÍCITA "Registrar no C5"
    public function handoff(Request $r, int $id): JsonResponse
    {
        $c = $this->candFor($r, $id);
        if (! $c) { return response()->json(['message' => 'Candidato não encontrado.'], 404); }
        if (! $this->hasPerm($r->user(), 'prosight.operations.patch.register')) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $res = $this->exec->handoff($c, (int) $r->user()->id);
        if (! ($res['ok'] ?? false)) {
            return response()->json(['error' => $res['error'], 'rpo_artifact_id' => $res['rpo_artifact_id'] ?? null, 'message' => $this->msg($res['error'])], (int) ($res['status'] ?? 422));
        }
        return response()->json(['data' => ['candidate' => $this->candidateView($res['candidate'], true), 'rpo_artifact_id' => $res['rpo_artifact_id']]]);
    }

    /** View do candidate com labels HONESTOS: SIMULADO; candidato≠registrado≠qualificado≠publicado. */
    private function candidateView(PatchArtifactCandidate $c, bool $full = false): array
    {
        $registered = $c->handoff_status === PatchArtifactCandidate::HANDOFF_REGISTERED;
        $simulated = (bool) ($c->provenance['simulated'] ?? true);
        $sfx = $simulated ? ' (SIMULADO)' : '';
        $view = [
            'id' => $c->id, 'patch_execution_id' => $c->patch_execution_id, 'environment_id' => $c->environment_id,
            'candidate_digest' => $c->candidate_digest, 'base_rpo_digest' => $c->base_rpo_digest, 'batch_digest' => $c->batch_digest,
            'classification' => $c->classification, 'handoff_status' => $c->handoff_status, 'is_simulated' => $simulated,
            // Estados honestos: registrado no C5 NÃO é qualificado NEM publicado.
            'is_registered' => $registered, 'is_qualified' => false, 'is_published' => false,
            'rpo_artifact_id' => $c->rpo_artifact_id,
            // Navegação (não executa operação): FE abre o artefato no C5/Operações RPO.
            'c5_artifact_nav' => $registered && $c->rpo_artifact_id ? "/prosight/rpo/artifacts/{$c->rpo_artifact_id}" : null,
            'label' => $registered
                ? 'Registrado no C5 — ainda não qualificado' . $sfx
                : 'Artefato candidato' . $sfx . ' — ainda não registrado',
            'note' => 'Patch aplicado ao workspace ≠ Patch publicado em produção.',
        ];
        if ($full) { $view['provenance'] = $c->provenance; } // proveniência IMUTÁVEL (execution/base/batch/itens/simulated)
        return $view;
    }

    // ── AGENTE (connector.agent) — claim/ack/result. Em P2 o "agente" é o harness simulado. ──

    // GET /connector/patch-executions/next — claim single-shot da execução CLAIMED do ambiente do agente.
    public function next(Request $r): JsonResponse
    {
        $agent = $r->attributes->get('connector_agent');
        $ex = PatchExecution::where('status', PatchExecution::ST_CLAIMED)
            ->whereIn('patch_request_id', PatchRequest::where('environment_id', $agent->environment_id)->pluck('id'))
            ->orderBy('id')->first();
        return $ex ? response()->json(['data' => $this->agentView($ex)]) : response()->json(null, 204);
    }

    // POST /connector/patch-executions/{id}/ack {execution_id, phase, item_order?} — marcadores de journal.
    public function ack(Request $r, int $id): JsonResponse
    {
        $agent = $r->attributes->get('connector_agent');
        $data = $r->validate([
            'execution_id' => 'required|uuid',
            'phase' => 'required|in:base_verified,patch_effect_started,patch_item_started,patch_item_committed,patch_effect_committed,artifact_verified',
            'item_order' => 'nullable|integer|min:1',
        ]);
        $ex = $this->agentExec($agent, $id);
        if (! $ex || $ex->execution_id !== $data['execution_id']) { return response()->json(['message' => 'Execução não encontrada.'], 404); }
        $res = $this->exec->ack($ex, $data['phase'], isset($data['item_order']) ? (int) $data['item_order'] : null);
        return response()->json(['ok' => $res['ok'], 'error' => $res['error'] ?? null], $res['ok'] ? 200 : (int) ($res['status'] ?? 409));
    }

    // POST /connector/patch-executions/{id}/result {execution_id, outcome, candidate_digest?}
    public function result(Request $r, int $id): JsonResponse
    {
        $agent = $r->attributes->get('connector_agent');
        $data = $r->validate([
            'execution_id' => 'required|uuid',
            'outcome' => 'required|in:success,failed,partial',
            'candidate_digest' => 'nullable|string|size:64',
        ]);
        $ex = $this->agentExec($agent, $id);
        if (! $ex || $ex->execution_id !== $data['execution_id']) { return response()->json(['message' => 'Execução não encontrada.'], 404); }
        $res = $this->exec->result($ex, $data['outcome'], $data['candidate_digest'] ?? null, (int) ($ex->created_by ?? 0));
        return response()->json(['ok' => $res['ok'], 'error' => $res['error'] ?? null], $res['ok'] ? 200 : (int) ($res['status'] ?? 409));
    }

    /** Payload ao AGENTE: identidade/digest pinados (ZERO bytes/path/PTM). */
    private function agentView(PatchExecution $x): array
    {
        return [
            'execution_id' => $x->execution_id, 'workspace_unit_id' => $x->workspace_unit_id,
            'execution_mode' => $x->execution_mode, 'fence_token' => (int) $x->fence_token,
            'base_rpo_hash' => $x->base_rpo_hash, 'batch_digest' => $x->batch_digest,
            'items' => $x->items()->get()->map(fn ($i) => ['batch_order' => $i->batch_order, 'item_digest' => $i->item_digest])->all(),
        ];
    }

    /** View com labels HONESTOS: SIMULADO explícito; candidate ≠ registrado ≠ publicado ≠ aplicado. */
    private function executionView(PatchExecution $x): array
    {
        $simulated = $x->execution_mode !== PatchRequest::MODE_LIVE;
        return [
            'id' => $x->id, 'execution_id' => $x->execution_id, 'workspace_unit_id' => $x->workspace_unit_id,
            'execution_mode' => $x->execution_mode, 'status' => $x->status, 'outcome' => $x->outcome,
            'fence_token' => (int) $x->fence_token, 'candidate_digest' => $x->candidate_digest,
            'applied_items' => $x->applied_items, 'reconciliation_state' => $x->reconciliation_state,
            'is_simulated' => $simulated,
            // P3 — link para o candidate produzido (jornada execução→candidato→registrar no C5).
            'candidate_id' => $x->status === PatchExecution::ST_CANDIDATE
                ? PatchArtifactCandidate::where('patch_execution_id', $x->id)->value('id') : null,
            'markers' => [
                'execution_committed' => (bool) $x->execution_committed_at, 'base_verified' => (bool) $x->base_verified_at,
                'patch_effect_started' => (bool) $x->patch_effect_started_at, 'patch_effect_committed' => (bool) $x->patch_effect_committed_at,
                'artifact_verified' => (bool) $x->artifact_verified_at,
            ],
            // NUNCA "aplicado/publicado/ativado". Patch produz candidate; C5 registra/publica.
            'is_registered' => false, 'is_published' => false,
            'label' => $this->execLabel($x, $simulated),
        ];
    }

    private function execLabel(PatchExecution $x, bool $simulated): string
    {
        $sfx = $simulated ? ' (SIMULADO)' : '';
        return match ($x->status) {
            PatchExecution::ST_CANDIDATE => 'Artefato candidato' . $sfx . ' — ainda não registrado no C5',
            PatchExecution::ST_FAILED => 'Execução falhou' . $sfx . ' — nenhum artefato',
            PatchExecution::ST_PARTIAL => 'Lote parcial' . $sfx . ' — nenhum artefato; exige nova execução + re-seed da base',
            PatchExecution::ST_INDETERMINATE => 'Indeterminado' . $sfx . ' — reconciliação/re-seed obrigatórios antes de nova execução',
            PatchExecution::ST_CONTRADICTED => 'Contraditado' . $sfx . ' — evidência incompatível',
            default => 'Execução em andamento' . $sfx,
        };
    }

    private function inputView(PatchInput $x): array
    {
        return [
            'id' => $x->id, 'patch_id' => $x->patch_id, 'source_ref' => $x->source_ref, 'digest' => $x->digest,
            'provenance' => $x->provenance, 'version' => $x->version, 'release' => $x->release,
            'compatibility' => $x->compatibility, 'classification' => $x->classification,
        ];
    }

    private function requestView(PatchRequest $x): array
    {
        return [
            'id' => $x->id, 'environment_id' => $x->environment_id, 'base_rpo_hash' => $x->base_rpo_hash,
            'execution_mode' => $x->execution_mode, 'workspace_unit_id' => $x->workspace_unit_id,
            'batch_digest' => $x->batch_digest, 'classification' => $x->classification, 'status' => $x->status,
            'correlation_id' => $x->correlation_id, 'requested_at' => optional($x->requested_at)->toIso8601String(),
            // Labels honestos: Patch produz artefato; C5 publica.
            'is_registered' => false, 'is_published' => false,
        ];
    }

    private function msg(string $e): string
    {
        return match ($e) {
            'invalid_digest', 'invalid_base_rpo_hash' => 'Digest inválido (sha256 hex).',
            'invalid_mode', 'mode_not_executable' => 'Modo de patch indisponível.',
            'empty_batch' => 'Lote de patches vazio.',
            'duplicate_in_batch' => 'Patch duplicado no lote.',
            'input_not_found' => 'Patch não encontrado neste ambiente.',
            'workspace_unit_required' => 'Unidade de workspace obrigatória para executar.',
            'execution_in_progress' => 'Já existe execução em andamento para esta request.',
            'request_canceled' => 'Request cancelada.',
            'workspace_busy' => 'Workspace ocupado por outra execução mutável (lock ativo).',
            'workspace_indeterminate' => 'Workspace retido por execução indeterminada — reconciliação/re-seed obrigatórios.',
            'live_unavailable' => 'Execução real de patch ainda não disponível (aguardando conector TOTVS).',
            'already_registered' => 'Artefato já registrado no C5.',
            'candidate_not_registerable' => 'Só um artefato candidato válido pode ser registrado no C5.',
            'register_failed', 'provenance_and_compatibility_required', 'invalid_hash' => 'Falha ao registrar no C5.',
            default => 'Não foi possível processar o patch.',
        };
    }
}
