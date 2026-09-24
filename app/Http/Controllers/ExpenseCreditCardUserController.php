<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Whitelist de usuários autorizados a lançar despesa via CARTÃO DE CRÉDITO da empresa.
 * A autorização é o flag booleano users.can_expense_credit_card.
 */
class ExpenseCreditCardUserController extends Controller
{
    private function ensureAdmin(): ?JsonResponse
    {
        $u = Auth::user();
        if (!$u || (!$u->isAdmin() && !$u->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Acesso negado.'], 403);
        }
        return null;
    }

    /** Lista os usuários autorizados. */
    public function index(): JsonResponse
    {
        if ($deny = $this->ensureAdmin()) return $deny;

        $users = User::where('can_expense_credit_card', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json(['success' => true, 'data' => $users]);
    }

    /** Autoriza um usuário (seta o flag). */
    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->ensureAdmin()) return $deny;

        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $user = User::findOrFail($data['user_id']);
        $user->can_expense_credit_card = true;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Usuário autorizado ao cartão de crédito.',
            'data'    => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ], 201);
    }

    /** Remove a autorização de um usuário. */
    public function destroy(User $user): JsonResponse
    {
        if ($deny = $this->ensureAdmin()) return $deny;

        $user->can_expense_credit_card = false;
        $user->save();

        return response()->json(['success' => true, 'message' => 'Autorização removida.']);
    }
}
