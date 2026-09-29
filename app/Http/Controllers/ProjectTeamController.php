<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Equipe do projeto definida na VISÃO GERAL: consultores (project_consultants +
 * coordenadores) e clientes (project_client_viewers). As atividades do cronograma
 * e os acompanhamentos só oferecem essas pessoas nos seletores.
 */
class ProjectTeamController extends Controller
{
    /** Pessoas do projeto pra alimentar os seletores das atividades. */
    public function team(Project $project): JsonResponse
    {
        // Consultores já ALOCADOS no cronograma (compat: não some quem já está alocado).
        $allocated = User::whereIn('id', function ($q) use ($project) {
            $q->select('sa.user_id')->from('stage_allocations as sa')
              ->join('project_stages as ps', 'ps.id', '=', 'sa.stage_id')
              ->where('ps.project_id', $project->id);
        })->get(['id', 'name']);

        $consultores = collect()
            ->merge($project->consultants()->select('users.id', 'users.name')->get())
            ->merge($project->coordinators()->select('users.id', 'users.name')->get())
            ->merge($allocated)
            ->unique('id')->sortBy('name')->values()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        $clientes = $project->clientViewers()->select('users.id', 'users.name', 'users.email')
            ->orderBy('users.name')->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        return response()->json(['consultores' => $consultores, 'clientes' => $clientes]);
    }

    /** Lista os consultores do projeto (project_consultants) — gestão na Visão Geral. */
    public function consultants(Project $project): JsonResponse
    {
        // Self-healing: projetos antigos têm equipe via ALOCAÇÃO nas etapas, mas
        // project_consultants vazio. Semeia quem já está alocado (consultor/parceiro
        // ativo) para o bloco refletir a equipe real e ficar gerenciável.
        $allocatedIds = User::whereIn('id', function ($q) use ($project) {
            $q->select('sa.user_id')->from('stage_allocations as sa')
              ->join('project_stages as ps', 'ps.id', '=', 'sa.stage_id')
              ->where('ps.project_id', $project->id);
        })->whereIn('type', ['consultor', 'parceiro_admin'])->pluck('id')->all();

        $missing = array_diff($allocatedIds, $project->consultants()->pluck('users.id')->all());
        if (!empty($missing)) {
            $project->consultants()->syncWithoutDetaching($missing);
        }

        $items = $project->consultants()
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy('users.name')->get();

        return response()->json(['items' => $items]);
    }

    public function addConsultant(Project $project, Request $request): JsonResponse
    {
        if (($err = $this->ensureCanManage($request)) !== null) return $err;

        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $user = User::find($data['user_id']);
        if (!$user || !in_array($user->type, ['consultor', 'parceiro_admin'], true)) {
            return response()->json(['message' => 'Só consultor ou parceiro pode entrar na equipe do projeto.'], 422);
        }

        $project->consultants()->syncWithoutDetaching([$user->id]);

        return response()->json(['item' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]], 201);
    }

    public function removeConsultant(Project $project, User $user, Request $request): JsonResponse
    {
        if (($err = $this->ensureCanManage($request)) !== null) return $err;

        $project->consultants()->detach($user->id);

        return response()->json(['detached' => true]);
    }

    /** Consultores e PARCEIROS ainda não na equipe (pra adicionar). Exclui candidatos. */
    public function availableConsultants(Project $project): JsonResponse
    {
        $already = $project->consultants()->pluck('users.id')->all();
        $items = User::whereIn('type', ['consultor', 'parceiro_admin'])
            ->whereNotIn('id', $already)
            ->where('enabled', true)
            ->where(function ($q) {
                $q->whereNull('consultant_type')->orWhere('consultant_type', '!=', 'candidate');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json(['items' => $items]);
    }

    private function ensureCanManage(Request $request): ?JsonResponse
    {
        $u = $request->user();
        $can = $u && ((method_exists($u, 'isAdmin') && $u->isAdmin()) || (method_exists($u, 'isCoordenador') && $u->isCoordenador()));
        if (!$can) return response()->json(['message' => 'Apenas coordenador ou admin podem gerir a equipe.'], 403);
        return null;
    }
}
