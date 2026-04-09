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
     * Lista todos os usuários internos (para tela de gestão)
     */
    public function all(Request $request): JsonResponse
    {
        $filter = $request->query('filter');
        $perPage = (int) $request->query('pageSize', 50);

        $query = User::whereNull('customer_id')
            ->with('roles')
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
     * Alterna o status de executivo de um usuário
     */
    public function toggle(User $user): JsonResponse
    {
        $user->update(['is_executive' => !$user->is_executive]);

        return response()->json($user->fresh());
    }
}
