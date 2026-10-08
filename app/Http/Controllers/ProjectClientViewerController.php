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
    public function index(Project $project, Request $request): JsonResponse
    {
        if (($err = $this->ensureCanManage($request, $project)) !== null) return $err;

        $items = $project->clientViewers()
            ->where('users.enabled', true)   // só exibe participantes ativos
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy('users.name')
            ->get();

        return response()->json(['items' => $items]);
    }

    /**
     * Clientes ELEGÍVEIS: só os do MESMO customer do projeto, ainda não vinculados.
     * Impede vincular um cliente de outro customer (vazamento entre clientes).
     */
    public function available(Project $project, Request $request): JsonResponse
    {
        if (($err = $this->ensureCanManage($request, $project)) !== null) return $err;

        if (!$project->customer_id) {
            return response()->json(['items' => []]);
        }

        $already = $project->clientViewers()->pluck('users.id')->all();

        $items = User::where('type', 'cliente')
            ->where('customer_id', $project->customer_id)
            ->where('enabled', true)   // só clientes ativos podem ser convidados
            ->whereNotIn('id', $already)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json(['items' => $items]);
    }

    public function store(Project $project, Request $request): JsonResponse
    {
        if (($err = $this->ensureCanManage($request, $project)) !== null) return $err;

        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $user = User::find($data['user_id']);
        if (!$user || !$user->isCliente()) {
            return response()->json(['message' => 'Só usuários do tipo cliente podem ter visão global do projeto.'], 422);
        }
        if (!$user->enabled) {
            return response()->json(['message' => 'Este cliente está inativo e não pode ser convidado.'], 422);
        }

        // Guard: o cliente precisa ser do MESMO customer do projeto.
        if ((int) $user->customer_id !== (int) $project->customer_id) {
            return response()->json(['message' => 'Este cliente é de outro cliente/empresa e não pode ver este projeto.'], 422);
        }

        $project->clientViewers()->syncWithoutDetaching([$user->id]);

        // Convidar implica acesso ao módulo Projetos — senão o cliente não enxerga o card
        // no menu e o EnsureClienteModule bloquearia a rota. null = todos já inclui projetos.
        $mods = $user->allowed_modules;
        if (is_array($mods) && !in_array('projetos', $mods, true)) {
            $user->allowed_modules = array_values(array_merge($mods, ['projetos']));
            $user->save();
        }

        return response()->json([
            'item' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ], 201);
    }

    public function destroy(Project $project, User $user, Request $request): JsonResponse
    {
        if (($err = $this->ensureCanManage($request, $project)) !== null) return $err;

        $project->clientViewers()->detach($user->id);

        return response()->json(['detached' => true]);
    }

    /**
     * Quem pode gerir os participantes do projeto:
     *  - admin e coordenador (equipe interna);
     *  - gestor do cliente (is_customer_manager) do MESMO customer do projeto.
     */
    private function ensureCanManage(Request $request, Project $project): ?JsonResponse
    {
        $u = $request->user();
        $isTeam = $u && (
            (method_exists($u, 'isAdmin') && $u->isAdmin())
            || (method_exists($u, 'isCoordenador') && $u->isCoordenador())
        );
        $isClientManager = $u
            && method_exists($u, 'isCliente') && $u->isCliente()
            && $u->is_customer_manager
            && (int) $u->customer_id === (int) $project->customer_id;

        if (!$isTeam && !$isClientManager) {
            return response()->json(['message' => 'Apenas coordenador, admin ou gestor do cliente podem gerir os participantes do projeto.'], 403);
        }
        return null;
    }
}
