<?php

namespace App\Http\Controllers;

use App\Models\CrmSalesTeam;
use App\Models\User;
use App\Services\PolicyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRM — cadastro de Equipes de Vendas (gestor + membros). Alimenta o escopo "Equipe"
 * da Política Comercial. Gestão restrita a admin/administrativo/policy.manage.
 */
class CrmSalesTeamController extends Controller
{
    public function __construct(private PolicyResolver $resolver) {}

    private function assertManage(): void
    {
        $u = auth()->user();
        abort_unless($u && ($u->isAdmin() || $u->type === 'administrativo' || $this->resolver->can($u, 'crm', 'policy.manage')), 403, 'Sem acesso à gestão de equipes.');
    }

    private function candidatos()
    {
        return User::where('is_crm_responsavel', true)->orderBy('name')->get(['id', 'name', 'type']);
    }

    private function present(CrmSalesTeam $t): array
    {
        return [
            'id' => $t->id, 'name' => $t->name, 'active' => (bool) $t->active,
            'manager' => $t->manager?->only(['id', 'name']),
            'members' => $t->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
        ];
    }

    public function index(): JsonResponse
    {
        $this->assertManage();
        $teams = CrmSalesTeam::with(['manager:id,name', 'members:id,name'])->orderBy('name')->get();
        return response()->json(['data' => [
            'teams' => $teams->map(fn ($t) => $this->present($t))->values(),
            'candidatos' => $this->candidatos(),
        ]]);
    }

    public function store(Request $r): JsonResponse
    {
        $this->assertManage();
        $v = $r->validate([
            'name' => 'required|string|max:120',
            'manager_id' => 'nullable|exists:users,id',
            'member_ids' => 'array',
            'member_ids.*' => 'integer|exists:users,id',
        ]);
        $t = CrmSalesTeam::create(['name' => $v['name'], 'manager_id' => $v['manager_id'] ?? null, 'active' => true]);
        $t->members()->sync($v['member_ids'] ?? []);
        return response()->json(['data' => $this->present($t->fresh(['manager', 'members']))], 201);
    }

    public function update(Request $r, CrmSalesTeam $team): JsonResponse
    {
        $this->assertManage();
        $v = $r->validate([
            'name' => 'sometimes|required|string|max:120',
            'manager_id' => 'nullable|exists:users,id',
            'active' => 'sometimes|boolean',
            'member_ids' => 'array',
            'member_ids.*' => 'integer|exists:users,id',
        ]);
        $team->fill(array_filter([
            'name' => $v['name'] ?? null,
        ], fn ($x) => $x !== null));
        if (array_key_exists('manager_id', $v)) $team->manager_id = $v['manager_id'];
        if (array_key_exists('active', $v)) $team->active = $v['active'];
        $team->save();
        if (array_key_exists('member_ids', $v)) $team->members()->sync($v['member_ids']);
        return response()->json(['data' => $this->present($team->fresh(['manager', 'members']))]);
    }

    public function destroy(CrmSalesTeam $team): JsonResponse
    {
        $this->assertManage();
        $team->members()->detach();
        $team->delete();
        return response()->json(['data' => ['deleted' => true]]);
    }
}
