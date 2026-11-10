<?php

namespace App\Http\Controllers;

use App\Models\MeetingGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Grupos de reunião (Central de Reunião). Mesmo gate da Central (admin + coordenador).
 * Todos os gestores enxergam e usam todos os grupos; editar/excluir = criador ou admin.
 */
class MeetingGroupController extends Controller
{
    private const MANAGERS = ['admin', 'coordenador'];

    private function manager(Request $request): User
    {
        $u = $request->user();
        abort_unless($u && in_array($u->type, self::MANAGERS, true), 403);
        return $u;
    }

    private function canEdit(User $u, MeetingGroup $g): bool
    {
        return $u->isAdmin() || (int) $g->created_by_id === (int) $u->id;
    }

    private function serialize(MeetingGroup $g, User $u): array
    {
        return [
            'id'         => $g->id,
            'name'       => $g->name,
            'creator'    => $g->creator?->name,
            'can_edit'   => $this->canEdit($u, $g),
            'members'    => $g->members->sortBy('name')->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values()->all(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $u = $this->manager($request);
        $groups = MeetingGroup::with(['creator:id,name', 'members:id,name'])->orderBy('name')->get();
        return response()->json(['data' => $groups->map(fn ($g) => $this->serialize($g, $u))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $this->manager($request);
        $v = $request->validate([
            'name'         => 'required|string|max:150',
            'member_ids'   => 'present|array',
            'member_ids.*' => 'integer|exists:users,id',
        ]);
        $g = MeetingGroup::create(['name' => trim($v['name']), 'created_by_id' => $u->id]);
        $g->members()->sync(collect($v['member_ids'])->unique()->values()->all());
        $g->load(['creator:id,name', 'members:id,name']);
        return response()->json(['data' => $this->serialize($g, $u)], 201);
    }

    public function update(Request $request, int $group): JsonResponse
    {
        $u = $this->manager($request);
        $g = MeetingGroup::findOrFail($group);
        abort_unless($this->canEdit($u, $g), 403, 'Apenas quem criou o grupo (ou admin) pode editá-lo.');
        $v = $request->validate([
            'name'         => 'sometimes|required|string|max:150',
            'member_ids'   => 'sometimes|array',
            'member_ids.*' => 'integer|exists:users,id',
        ]);
        if (array_key_exists('name', $v)) $g->update(['name' => trim($v['name'])]);
        if (array_key_exists('member_ids', $v)) $g->members()->sync(collect($v['member_ids'])->unique()->values()->all());
        $g->load(['creator:id,name', 'members:id,name']);
        return response()->json(['data' => $this->serialize($g, $u)]);
    }

    public function destroy(Request $request, int $group): JsonResponse
    {
        $u = $this->manager($request);
        $g = MeetingGroup::findOrFail($group);
        abort_unless($this->canEdit($u, $g), 403, 'Apenas quem criou o grupo (ou admin) pode excluí-lo.');
        $g->delete();
        return response()->json(['ok' => true]);
    }
}
