<?php

namespace App\Http\Controllers;

use App\Connector\RpoRegistryService;
use App\Models\EnvEnvironment;
use App\Models\RpoArtifact;
use App\Models\RpoQualification;
use App\Models\RpoTarget;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Connector-5.1 — FUNDAÇÃO de publicação de RPO (saber/registrar/qualificar/agrupar/prever). ZERO
 * publicação: nenhum endpoint cria connector_operation, execution_id, claim ou efeito no agente.
 * Escopo por customer_id (anti-IDOR 404). Nenhum byte/path de artefato transita.
 */
class RpoRegistryController extends Controller
{
    public function __construct(
        private RpoRegistryService $rpo,
        private SourceDocCustomerScope $scope,
    ) {
    }

    private function env(Request $r, int $id): ?EnvEnvironment
    {
        $env = EnvEnvironment::query()->whereKey($id)->first(['id', 'customer_id', 'type']);

        return ($env && $this->scope->canAccessCustomerId($r->user(), (int) $env->customer_id)) ? $env : null;
    }

    private function artifact(Request $r, int $id): ?RpoArtifact
    {
        $a = RpoArtifact::find($id);

        return ($a && $this->scope->canAccessCustomerId($r->user(), (int) $a->customer_id)) ? $a : null;
    }

    private function target(Request $r, int $id): ?RpoTarget
    {
        $t = RpoTarget::find($id);

        return ($t && $this->scope->canAccessCustomerId($r->user(), (int) $t->customer_id)) ? $t : null;
    }

    // ── Capability ────────────────────────────────────────────────────────────
    public function capability(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }

        return response()->json(['data' => $this->rpo->capability((int) $env->id)]);
    }

    // ── Artefatos ─────────────────────────────────────────────────────────────
    public function artifacts(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $registered = RpoArtifact::where('customer_id', $env->customer_id)->whereNull('superseded_by_id')->orderByDesc('id')->limit(200)->get()->map(fn ($a) => $this->artifactView($a))->all();

        return response()->json(['data' => [
            'registered' => $registered,
            'discovered' => $this->rpo->discovered((int) $env->id, (int) $env->customer_id), // NÃO confiável
        ]]);
    }

    public function register(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $data = $r->validate([
            'hash' => 'required|string|size:64', 'version' => 'nullable|string|max:60',
            'provenance' => 'required|string|max:300', 'compatibility' => 'required|array',
            'compatibility.appserver_versions' => 'nullable|array', 'source_identity' => 'nullable|string|max:200',
        ]);
        $res = $this->rpo->register((int) $env->id, (int) $env->customer_id, $data, $r->user()->id);
        if (! $res['ok']) { return response()->json(['error' => $res['error']], 422); }

        return response()->json(['data' => $this->artifactView($res['artifact'])], 201);
    }

    public function revise(Request $r, int $id): JsonResponse
    {
        $old = $this->artifact($r, $id);
        if (! $old) { return response()->json(['message' => 'Artefato não encontrado.'], 404); }
        $data = $r->validate(['version' => 'nullable|string|max:60', 'provenance' => 'required|string|max:300', 'compatibility' => 'required|array', 'source_identity' => 'nullable|string|max:200']);
        $res = $this->rpo->revise($old, $data, $r->user()->id);
        if (! $res['ok']) { return response()->json(['error' => $res['error']], $res['error'] === 'already_superseded' ? 409 : 422); }

        return response()->json(['data' => $this->artifactView($res['artifact'])], 201);
    }

    public function showArtifact(Request $r, int $id): JsonResponse
    {
        $a = $this->artifact($r, $id);
        if (! $a) { return response()->json(['message' => 'Artefato não encontrado.'], 404); }

        return response()->json(['data' => $this->artifactView($a)]);
    }

    // ── Targets ───────────────────────────────────────────────────────────────
    public function createTarget(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $data = $r->validate(['name' => 'required|string|max:120', 'appserver_refs' => 'required|array|min:1', 'appserver_refs.*' => 'required|uuid']);
        $res = $this->rpo->createTarget((int) $env->id, (int) $env->customer_id, $data['name'], $data['appserver_refs'], $r->user()->id);
        if (! $res['ok']) { return response()->json(['error' => $res['error']], $res['error'] === 'appserver_already_in_target' ? 409 : 422); }

        return response()->json(['data' => $this->targetView($res['target'])], 201);
    }

    public function confirmTarget(Request $r, int $id): JsonResponse
    {
        $t = $this->target($r, $id);
        if (! $t) { return response()->json(['message' => 'Target não encontrado.'], 404); }
        $res = $this->rpo->confirmTarget($t, $r->user()->id);
        if (! $res['ok']) { return response()->json(['error' => $res['error'], 'consistency' => $res['consistency'] ?? null], 422); }

        return response()->json(['data' => $this->targetView($res['target'])]);
    }

    public function targets(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $rows = RpoTarget::where('environment_id', $env->id)->orderByDesc('id')->get();

        return response()->json(['data' => ['environment_id' => (int) $env->id, 'targets' => $rows->map(fn ($t) => $this->targetView($t))->all()]]);
    }

    public function showTarget(Request $r, int $id): JsonResponse
    {
        $t = $this->target($r, $id);
        if (! $t) { return response()->json(['message' => 'Target não encontrado.'], 404); }

        return response()->json(['data' => $this->targetView($t)]);
    }

    // ── Qualificação known_good (contextual) ──────────────────────────────────
    public function qualify(Request $r, int $id): JsonResponse
    {
        $t = $this->target($r, $id);
        if (! $t) { return response()->json(['message' => 'Target não encontrado.'], 404); }
        $data = $r->validate(['artifact_id' => 'required|integer', 'reason' => 'required|string|max:300']);
        $art = $this->artifact($r, (int) $data['artifact_id']);
        if (! $art) { return response()->json(['message' => 'Artefato não encontrado.'], 404); }
        $res = $this->rpo->qualify($t, $art, $data['reason'], $r->user()->id);
        if (! $res['ok']) { return response()->json(['error' => $res['error']], 422); }

        return response()->json(['data' => $this->qualView($res['qualification'])], 201);
    }

    public function revokeQualification(Request $r, int $id): JsonResponse
    {
        $q = RpoQualification::find($id);
        if (! $q || ! $this->scope->canAccessCustomerId($r->user(), (int) (RpoTarget::find($q->rpo_target_id)->customer_id ?? -1))) {
            return response()->json(['message' => 'Qualificação não encontrada.'], 404);
        }
        $res = $this->rpo->revokeQualification($q, $r->user()->id);
        if (! $res['ok']) { return response()->json(['error' => $res['error']], 409); }

        return response()->json(['data' => $this->qualView($res['qualification'])]);
    }

    public function qualifications(Request $r, int $id): JsonResponse
    {
        $t = $this->target($r, $id);
        if (! $t) { return response()->json(['message' => 'Target não encontrado.'], 404); }
        $hist = RpoQualification::where('rpo_target_id', $t->id)->orderByDesc('qualified_at')->orderByDesc('id')->get()->map(fn ($q) => $this->qualView($q))->all();
        $lkg = $this->rpo->lastKnownGood($t);

        return response()->json(['data' => ['history' => $hist, 'last_known_good' => $lkg ? $this->qualView($lkg) : null]]);
    }

    // ── Preview (read-only; ZERO efeito) ──────────────────────────────────────
    public function preview(Request $r, int $id): JsonResponse
    {
        $t = $this->target($r, $id);
        if (! $t) { return response()->json(['message' => 'Target não encontrado.'], 404); }
        $data = $r->validate(['to_artifact_id' => 'required|integer', 'is_rollback' => 'nullable|boolean']);
        $to = $this->artifact($r, (int) $data['to_artifact_id']);
        if (! $to) { return response()->json(['message' => 'Artefato não encontrado.'], 404); }

        return response()->json(['data' => $this->rpo->preview($t, $to, (bool) ($data['is_rollback'] ?? false))]);
    }

    // ── views ─────────────────────────────────────────────────────────────────
    private function artifactView(RpoArtifact $a): array
    {
        return ['id' => $a->id, 'hash' => $a->hash, 'version' => $a->version, 'provenance' => $a->provenance,
            'compatibility' => $a->compatibility, 'source_identity' => $a->source_identity, 'status' => $a->status,
            'revision' => (int) $a->revision, 'supersedes_id' => $a->supersedes_id, 'superseded_by_id' => $a->superseded_by_id,
            'registered_by' => $a->registered_by, 'registered_at' => $a->registered_at?->toIso8601String()];
    }

    private function targetView(RpoTarget $t): array
    {
        $c = $this->rpo->targetConsistency($t);
        $lkg = $this->rpo->lastKnownGood($t);

        return ['id' => $t->id, 'environment_id' => (int) $t->environment_id, 'name' => $t->name, 'status' => $t->status,
            'appserver_refs' => $t->appservers()->pluck('appserver_ref')->all(), 'consistency' => $c,
            'last_known_good' => $lkg ? ['artifact_id' => $lkg->rpo_artifact_id, 'hash' => $lkg->hash, 'qualified_at' => $lkg->qualified_at?->toIso8601String()] : null,
            'confirmed_by' => $t->confirmed_by, 'confirmed_at' => $t->confirmed_at?->toIso8601String()];
    }

    private function qualView(RpoQualification $q): array
    {
        return ['id' => $q->id, 'rpo_artifact_id' => $q->rpo_artifact_id, 'rpo_target_id' => $q->rpo_target_id,
            'hash' => $q->hash, 'reason' => $q->reason, 'qualified_by' => $q->qualified_by,
            'qualified_at' => $q->qualified_at?->toIso8601String(), 'revoked_at' => $q->revoked_at?->toIso8601String()];
    }
}
