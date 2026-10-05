<?php

namespace App\Http\Controllers;

use App\Connector\RpoTopologyService;
use App\Models\EnvEnvironment;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RPO-DISCOVERY (C5.0) — tela "Integração RPO": topologia detectada → sugestões → confirmação governada.
 * Escopo por customer do AMBIENTE (anti-IDOR 404). Confirmação delega ao C5.1 (autoridade). NENHUMA rota
 * publica/promove RPO nem altera membership automaticamente. Zero path/INI/secret/bytes.
 */
class RpoTopologyController extends Controller
{
    public function __construct(
        private RpoTopologyService $topo,
        private SourceDocCustomerScope $scope,
    ) {
    }

    private function env(Request $r, int $id): ?EnvEnvironment
    {
        $env = EnvEnvironment::query()->whereKey($id)->first(['id', 'customer_id', 'type']);
        return ($env && $this->scope->canAccessCustomerId($r->user(), (int) $env->customer_id)) ? $env : null;
    }

    // GET /prosight/environments/{environmentId}/rpo/topology
    public function show(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        return response()->json(['data' => $this->topo->view((int) $env->id)]);
    }

    // POST /prosight/environments/{environmentId}/rpo/topology/confirm
    public function confirm(Request $r, int $environmentId): JsonResponse
    {
        $env = $this->env($r, $environmentId);
        if (! $env) { return response()->json(['message' => 'Ambiente não encontrado.'], 404); }
        $p = $r->validate([
            'publish_unit_id' => 'required|string|max:80|regex:/^[A-Za-z0-9_.:-]{1,80}$/',
            'member_refs' => 'required|array|min:1|max:100',
            'member_refs.*' => 'uuid',
            'observation_id' => 'nullable|string|max:64',
            'topology_revision' => 'required|integer',
            'topology_fingerprint' => 'required|string|size:64',
            'name' => 'nullable|string|max:120',
        ]);
        $res = $this->topo->confirm($env, $p, (int) $r->user()->id);
        if (! ($res['ok'] ?? false)) {
            $status = (int) ($res['status'] ?? 422);
            $body = ['error' => $res['error'], 'message' => $this->msg($res['error'])];
            foreach (['current_revision', 'current_members', 'consistency', 'target_id'] as $k) {
                if (isset($res[$k])) { $body[$k] = $res[$k]; }
            }
            return response()->json($body, $status);
        }
        return response()->json(['data' => [
            'ok' => true,
            'target_id' => $res['target']->id,
            'status' => $res['target']->status,
            'message' => 'Target RPO confirmado a partir da topologia observada. Publicação/rollback seguem em Operações RPO.',
        ]]);
    }

    private function msg(string $e): string
    {
        return match ($e) {
            'topology_observation_stale' => 'A topologia mudou desde que você abriu esta tela. Revise a topologia detectada e confirme novamente.',
            'topology_not_available' => 'Topologia ainda não detectada (ou desatualizada). Colete a configuração novamente.',
            'invalid_group' => 'Grupo de AppServers inválido.',
            'appserver_already_in_target' => 'Um dos AppServers já pertence a um target ativo.',
            'target_not_consistent' => 'O target observado ainda não está consistente para confirmação.',
            default => 'Não foi possível confirmar o target.',
        };
    }
}
