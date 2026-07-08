<?php

namespace App\Http\Controllers;

use App\Models\PipelineViewPermission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Liberação de visualização do pipeline "Demandas e Projetos" — por usuário (qualquer perfil).
 * Admin/Administrativo gerenciam. Ver App\Models\PipelineViewPermission::effectiveFor().
 */
class PipelineViewPermissionController extends Controller
{
    private function denyIfNotAdmin(): ?JsonResponse
    {
        $u = Auth::user();
        if (!$u || !($u->isAdmin() || (method_exists($u, 'isAdministrativo') && $u->isAdministrativo()))) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }
        return null;
    }

    /** Lista as liberações CUSTOM (só usuários com override) + dados p/ os pickers. */
    public function index(): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin()) return $deny;

        $rows = PipelineViewPermission::with('user:id,name,email,type,coordinator_type')
            ->get()
            ->map(fn ($p) => [
                'user_id'               => $p->user_id,
                'user_name'             => $p->user->name ?? '—',
                'user_email'            => $p->user->email ?? null,
                'user_type'             => $p->user->type ?? null,
                'coordinator_type'      => $p->user->coordinator_type ?? null,
                'demand_visible'        => (bool) $p->demand_visible,
                'demand_client_scope'   => $p->demand_client_scope ?: 'all',
                'demand_customer_ids'   => array_values(array_map('intval', $p->demand_customer_ids ?? [])),
                'project_visible'       => (bool) $p->project_visible,
                'project_client_scope'  => $p->project_client_scope ?: 'all',
                'project_customer_ids'  => array_values(array_map('intval', $p->project_customer_ids ?? [])),
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /** Cria/atualiza (upsert) a liberação de um usuário. */
    public function upsert(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin()) return $deny;

        $data = $request->validate([
            'user_id'               => 'required|integer|exists:users,id',
            'demand_visible'        => 'required|boolean',
            'demand_client_scope'   => 'required|in:all,specific',
            'demand_customer_ids'   => 'nullable|array',
            'demand_customer_ids.*' => 'integer|exists:customers,id',
            'project_visible'       => 'required|boolean',
            'project_client_scope'  => 'required|in:all,specific',
            'project_customer_ids'  => 'nullable|array',
            'project_customer_ids.*'=> 'integer|exists:customers,id',
        ]);

        // Escopo 'all' zera a lista de clientes (evita lixo).
        $demandIds  = $data['demand_client_scope'] === 'specific' ? array_values(array_unique($data['demand_customer_ids'] ?? [])) : [];
        $projectIds = $data['project_client_scope'] === 'specific' ? array_values(array_unique($data['project_customer_ids'] ?? [])) : [];

        $perm = PipelineViewPermission::updateOrCreate(
            ['user_id' => $data['user_id']],
            [
                'demand_visible'       => $data['demand_visible'],
                'demand_client_scope'  => $data['demand_client_scope'],
                'demand_customer_ids'  => $demandIds,
                'project_visible'      => $data['project_visible'],
                'project_client_scope' => $data['project_client_scope'],
                'project_customer_ids' => $projectIds,
            ]
        );

        return response()->json(['success' => true, 'data' => $perm]);
    }

    /** Remove a liberação (volta ao padrão do perfil). */
    public function destroy(int $userId): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin()) return $deny;

        PipelineViewPermission::where('user_id', $userId)->delete();
        return response()->json(['success' => true]);
    }
}
