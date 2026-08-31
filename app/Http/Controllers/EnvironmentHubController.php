<?php

namespace App\Http\Controllers;

use App\Connector\EnvironmentHubService;
use App\Models\ConnectorAppserverBinding;
use App\Models\EnvAppserver;
use App\Models\EnvEnvironment;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ENV-HUB — jornada operacional por AMBIENTE. operational-status (read-model) + reconciliação cadastral↔observado
 * (binding humano). Escopo por customer do ambiente (anti-IDOR 404). NÃO publica/promove RPO, NÃO faz enrollment
 * (deep-link ao Prosight), NÃO expõe secret/INI/path. Vínculo NUNCA automático.
 */
class EnvironmentHubController extends Controller
{
    public function __construct(
        private EnvironmentHubService $hub,
        private SourceDocCustomerScope $scope,
    ) {
    }

    private function env(Request $r, int $id): ?EnvEnvironment
    {
        $env = EnvEnvironment::query()->whereKey($id)->first(['id', 'customer_id', 'name', 'type', 'status']);
        return ($env && $this->scope->canAccessCustomerId($r->user(), (int) $env->customer_id)) ? $env : null;
    }

    // GET /prosight/environments/{environmentId}/operational-status
    public function status(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        return response()->json(['data' => $this->hub->operationalStatus($env, $r->user())]);
    }

    // GET /prosight/environments/{environmentId}/appservers/reconciliation
    public function reconciliation(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        return response()->json(['data' => $this->hub->reconciliation((int) $env->id)]);
    }

    // POST /prosight/environments/{environmentId}/appservers/{envAppserverId}/bind  (perm appserver.bind)
    public function bind(Request $r, int $environmentId, int $envAppserverId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $data = $r->validate(['appserver_ref' => 'required|uuid']);
        $res = $this->hub->confirmBinding($env, $envAppserverId, $data['appserver_ref'], (int) $r->user()->id);
        if (! ($res['ok'] ?? false)) {
            $body = ['error' => $res['error'], 'message' => $this->msg($res['error'])];
            if (isset($res['conflict_env_appserver_id'])) { $body['conflict_env_appserver_id'] = $res['conflict_env_appserver_id']; }
            return response()->json($body, (int) ($res['status'] ?? 422));
        }
        return response()->json(['data' => ['ok' => true, 'binding_id' => $res['binding']->id]]);
    }

    /**
     * POST /prosight/environments/{environmentId}/appservers/register-and-bind
     * {name, appserver_ref, version?, build?, patch?} — cria o AppServer CADASTRAL a partir de um
     * DETECTADO e já vincula (binding humano em 1 passo). Evita ter que cadastrar antes só p/ vincular.
     */
    public function registerAndBind(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $data = $r->validate([
            'name'          => 'required|string|max:120',
            'appserver_ref' => 'required|uuid',
            'version'       => 'nullable|string|max:60',
            'build'         => 'nullable|string|max:60',
            'patch'         => 'nullable|string|max:60',
        ]);

        // Reusa um cadastral de mesmo nome se já existir; senão cria.
        $app = EnvAppserver::firstOrNew(['environment_id' => $env->id, 'name' => $data['name']]);
        $app->version = $data['version'] ?? $app->version;
        $app->build   = $data['build'] ?? $app->build;
        $app->patch   = $data['patch'] ?? $app->patch;
        $app->created_by = $app->created_by ?? $r->user()?->id;
        $app->save();

        $res = $this->hub->confirmBinding($env, (int) $app->id, $data['appserver_ref'], (int) $r->user()->id);
        if (! ($res['ok'] ?? false)) {
            $body = ['error' => $res['error'], 'message' => $this->msg($res['error']), 'env_appserver_id' => (int) $app->id];
            if (isset($res['conflict_env_appserver_id'])) { $body['conflict_env_appserver_id'] = $res['conflict_env_appserver_id']; }
            return response()->json($body, (int) ($res['status'] ?? 422));
        }
        return response()->json(['data' => ['ok' => true, 'env_appserver_id' => (int) $app->id, 'binding_id' => $res['binding']->id]]);
    }

    // POST /prosight/environments/{environmentId}/appserver-bindings/{bindingId}/supersede  (perm appserver.bind)
    public function supersede(Request $r, int $environmentId, int $bindingId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $b = ConnectorAppserverBinding::find($bindingId);
        if (! $b || (int) $b->environment_id !== (int) $env->id) { return response()->json(['message' => 'Vínculo não encontrado.'], 404); }
        $data = $r->validate(['reason' => 'required|string|max:300']);
        $res = $this->hub->supersedeBinding($env, $b, $data['reason'], (int) $r->user()->id);
        if (! ($res['ok'] ?? false)) {
            return response()->json(['error' => $res['error'], 'message' => $this->msg($res['error'])], (int) ($res['status'] ?? 422));
        }
        return response()->json(['data' => ['ok' => true]]);
    }

    private function msg(string $e): string
    {
        return match ($e) {
            'cadastral_not_found' => 'AppServer cadastral não encontrado neste ambiente.',
            'ref_not_observed' => 'Este AppServer observado não está na coleta atual. Colete o inventário novamente.',
            'ref_already_bound' => 'Este AppServer observado já está vinculado a outro cadastral.',
            'not_active' => 'Este vínculo já não está ativo.',
            default => 'Não foi possível processar o vínculo.',
        };
    }
}
