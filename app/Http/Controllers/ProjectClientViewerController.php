<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestão dos clientes com VISÃO GLOBAL do projeto (nível projeto).
 * Interno — coordenador/admin. O cliente vinculado vê o projeto inteiro em dias.
 */
class ProjectClientViewerController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $items = $project->clientViewers()
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy('users.name')
            ->get();

        return response()->json(['items' => $items]);
    }

    /**
     * Clientes ELEGÍVEIS: só os do MESMO customer do projeto, ainda não vinculados.
     * Impede vincular um cliente de outro customer (vazamento entre clientes).
     */
    public function available(Project $project): JsonResponse
    {
        if (!$project->customer_id) {
            return response()->json(['items' => []]);
        }

        $already = $project->clientViewers()->pluck('users.id')->all();

        $items = User::where('type', 'cliente')
            ->where('customer_id', $project->customer_id)
            ->whereNotIn('id', $already)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json(['items' => $items]);
    }

    public function store(Project $project, Request $request): JsonResponse
    {
        if (($err = $this->ensureCanManage($request)) !== null) return $err;

        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $user = User::find($data['user_id']);
        if (!$user || !$user->isCliente()) {
            return response()->json(['message' => 'Só usuários do tipo cliente podem ter visão global do projeto.'], 422);
        }

        // Guard: o cliente precisa ser do MESMO customer do projeto.
        if ((int) $user->customer_id !== (int) $project->customer_id) {
            return response()->json(['message' => 'Este cliente é de outro cliente/empresa e não pode ver este projeto.'], 422);
        }

        $project->clientViewers()->syncWithoutDetaching([$user->id]);

        return response()->json([
            'item' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ], 201);
    }

    public function destroy(Project $project, User $user, Request $request): JsonResponse
    {
        if (($err = $this->ensureCanManage($request)) !== null) return $err;

        $project->clientViewers()->detach($user->id);

        return response()->json(['detached' => true]);
    }

    private function ensureCanManage(Request $request): ?JsonResponse
    {
        $u = $request->user();
        $can = $u && (
            (method_exists($u, 'isAdmin') && $u->isAdmin())
            || (method_exists($u, 'isCoordenador') && $u->isCoordenador())
        );
        if (!$can) {
            return response()->json(['message' => 'Apenas coordenador ou admin podem gerir os participantes do projeto.'], 403);
        }
        return null;
    }
}
