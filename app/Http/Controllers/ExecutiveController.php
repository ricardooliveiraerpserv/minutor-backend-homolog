<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutiveController extends Controller
{
    /**
     * Lista executivos (para po-combo com filtro)
     */
    public function index(Request $request): JsonResponse
    {
        $filter = $request->query('filter');
        $perPage = (int) $request->query('pageSize', 10);

        $query = User::where('is_executive', true)
            ->whereNull('customer_id')
            ->where('type', '!=', 'parceiro_admin')
            ->orderBy('name');

        if ($filter) {
            $query->where('name', 'ilike', "%{$filter}%");
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'hasNext' => $paginator->hasMorePages(),
            'items' => $paginator->items(),
        ]);
    }

    /**
     * Lista usuários internos candidatos a executivo (não são executivos ainda)
     */
    public function all(Request $request): JsonResponse
    {
        $filter = $request->query('filter');
        $perPage = (int) $request->query('pageSize', 50);

        $query = User::whereNull('customer_id')
            ->where('is_executive', false)
            ->where('enabled', true)
            ->where('type', '!=', 'parceiro_admin')
            ->orderBy('name');

        if ($filter) {
            $query->where('name', 'ilike', "%{$filter}%");
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'hasNext' => $paginator->hasMorePages(),
            'items' => $paginator->items(),
        ]);
    }

    /**
     * Seletor "Executivo Comercial" do CRM: quem for executivo (is_executive) + os admins,
     * que aparecem SEMPRE (mesmo sem a flag de executivo). Sem paginação — é um combo simples.
     */
    public function commercial(): JsonResponse
    {
        $users = User::query()
            ->whereNull('customer_id')
            ->where('enabled', true)
            ->where('type', '!=', 'parceiro_admin')
            ->where(fn ($q) => $q->where('is_executive', true)->orWhere('type', 'admin'))
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'is_executive']);

        return response()->json(['data' => $users]);
    }

    /**
     * Alterna o status de executivo de um usuário
     */
    public function toggle(User $user): JsonResponse
    {
        // is_executive é PROTECTED_FIELD — usar forceFill, não update().
        $user->forceFill(['is_executive' => !$user->is_executive])->save();

        return response()->json($user->fresh());
    }
}
