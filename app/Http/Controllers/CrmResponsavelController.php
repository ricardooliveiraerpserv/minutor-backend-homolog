<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRM — cadastro de Responsáveis Comerciais. Vincula/desvincula usuários como
 * responsáveis de oportunidade (forecast/metas/comissões). É só a flag
 * `users.is_crm_responsavel`; não cria entidade nova nem mexe em permissões.
 */
class CrmResponsavelController extends Controller
{
    /** Todos os usuários ativos + se são responsáveis comerciais (para a tela de cadastro). */
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->when($request->boolean('somente_responsaveis'), fn ($q) => $q->where('is_crm_responsavel', true))
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'is_executive', 'is_crm_responsavel']);
        return response()->json(['data' => $users]);
    }

    /** Vincula/desvincula um usuário como responsável comercial. */
    public function update(Request $request, User $user): JsonResponse
    {
        $v = $request->validate(['is_crm_responsavel' => 'required|boolean']);
        $user->is_crm_responsavel = $v['is_crm_responsavel'];
        $user->save();
        return response()->json(['data' => $user->only(['id', 'name', 'type', 'is_executive', 'is_crm_responsavel'])]);
    }
}
