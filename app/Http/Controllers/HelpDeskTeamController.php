<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Help Desk — Cadastro de filas/equipes de atendimento + membros. */
class HelpDeskTeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $teams = HelpDeskTeam::query()
            ->when(!$request->boolean('all'), fn ($q) => $q->where('active', true))
            ->with(['lead:id,name', 'members:id,name'])
            ->orderBy('sort_order')->orderBy('name')->get();
        return response()->json(['data' => $teams]);
    }

    private function rules(bool $creating): array
    {
        return [
            'name'         => ($creating ? 'required' : 'sometimes') . '|string|max:120',
            'description'  => 'nullable|string',
            'color'        => 'nullable|string|max:16',
            'lead_user_id' => 'nullable|exists:users,id',
            'active'       => 'nullable|boolean',
            'sort_order'   => 'nullable|integer',
            'member_ids'   => 'nullable|array',
            'member_ids.*' => 'integer|exists:users,id',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        $members = $v['member_ids'] ?? null; unset($v['member_ids']);
        $v['slug'] = Str::slug($v['name']);
        $team = HelpDeskTeam::create($v);
        if ($members) $team->members()->sync($members);
        return response()->json(['data' => $team->load('members:id,name')], 201);
    }

    public function update(Request $request, HelpDeskTeam $team): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        $members = $v['member_ids'] ?? null; unset($v['member_ids']);
        if (isset($v['name'])) $v['slug'] = Str::slug($v['name']);
        $team->update($v);
        if ($members !== null) $team->members()->sync($members);
        return response()->json(['data' => $team->fresh()->load('members:id,name')]);
    }

    public function destroy(HelpDeskTeam $team): JsonResponse
    {
        $team->delete(); // soft delete
        return response()->json(null, 204);
    }
}
