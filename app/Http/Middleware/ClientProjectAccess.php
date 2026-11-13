<?php

namespace App\Http\Middleware;

use App\Models\Contract;
use App\Models\ContractRequest;
use App\Models\Project;
use App\Support\ClientAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rede de segurança GLOBAL p/ o perfil CLIENTE: toda rota autenticada que recebe um
 * projeto na URL ({project}/{projectId}), contrato ({contract}) ou requisição ({contractRequest}) só passa se o cliente pode acessar aquele
 * projeto (mesmo customer E gestor/participante — App\Support\ClientAccess).
 *
 * Usuários internos passam direto (as regras deles continuam nos controllers).
 * Não substitui as checagens dos controllers; impede que um endpoint esquecido
 * devolva dados de projeto de OUTRO cliente (ou fora do menu do convidado).
 */
class ClientProjectAccess
{
    private const PARAMS = ['project', 'projectId'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! ClientAccess::isClient($user)) {
            return $next($request);
        }

        // Filtros vindos do cliente (query/body): customer_id de OUTRO customer ou project_id(s)
        // que ele não acessa → 403. Vários endpoints (dashboards, relatórios, listas) aceitam
        // project_id e buscavam o projeto direto, sem checar o customer.
        $cid = $request->input('customer_id');
        if (is_scalar($cid) && $cid !== '' && is_numeric($cid) && (int) $cid > 0
            && (int) $cid !== (int) $user->customer_id) {
            return response()->json(['message' => 'Acesso negado a dados de outro cliente.'], 403);
        }
        $pids = $this->inputProjectIds($request);
        if (! empty($pids)) {
            $q = Project::withoutGlobalScopes()->whereIn('id', $pids);
            ClientAccess::scopeProjects($q, $user);
            if ($q->count() !== count($pids)) {
                return response()->json(['message' => 'Você não participa deste projeto.'], 403);
            }
        }

        $route = $request->route();
        if ($route) {
            foreach (self::PARAMS as $name) {
                if (! $route->hasParameter($name)) {
                    continue;
                }
                $value = $route->parameter($name);
                $project = $value instanceof Project
                    ? $value
                    : (is_numeric($value) ? Project::withoutGlobalScopes()->find((int) $value) : null);

                if (! ClientAccess::canAccessProject($project, $user)) {
                    return response()->json(['message' => 'Você não participa deste projeto.'], 403);
                }
            }

            // Contrato (Demanda) e Requisição na URL — mesma regra do kanban do cliente.
            if ($route->hasParameter('contract')) {
                $v = $route->parameter('contract');
                $contract = $v instanceof Contract ? $v : (is_numeric($v) ? Contract::withoutGlobalScopes()->find((int) $v) : null);
                if (! ClientAccess::canAccessContract($contract, $user)) {
                    return response()->json(['message' => 'Sem acesso a este contrato.'], 403);
                }
            }
            if ($route->hasParameter('contractRequest')) {
                $v = $route->parameter('contractRequest');
                $req = $v instanceof ContractRequest ? $v : (is_numeric($v) ? ContractRequest::withoutGlobalScopes()->find((int) $v) : null);
                if (! ClientAccess::canAccessContractRequest($req, $user)) {
                    return response()->json(['message' => 'Sem acesso a esta requisição.'], 403);
                }
            }
        }

        return $next($request);
    }

    /** project_id / project_ids / parent_project_id enviados (escalar, array ou CSV). */
    private function inputProjectIds(Request $request): array
    {
        $ids = [];
        foreach (['project_id', 'project_ids', 'parent_project_id'] as $key) {
            $v = $request->input($key);
            if ($v === null || $v === '') {
                continue;
            }
            $vals = is_array($v) ? $v : (is_scalar($v) ? explode(',', (string) $v) : []);
            foreach ($vals as $x) {
                if (is_scalar($x) && is_numeric(trim((string) $x)) && (int) $x > 0) {
                    $ids[(int) $x] = true;
                }
            }
        }
        return array_keys($ids);
    }
}
