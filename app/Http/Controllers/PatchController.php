<?php

namespace App\Http\Controllers;

use App\Connector\PatchService;
use App\Models\EnvEnvironment;
use App\Models\PatchInput;
use App\Models\PatchRequest;
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
        private SourceDocCustomerScope $scope,
    ) {
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
            default => 'Não foi possível processar o patch.',
        };
    }
}
