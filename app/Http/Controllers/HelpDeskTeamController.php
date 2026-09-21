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
            'company_id'   => 'nullable|integer|exists:companies,id', // empresa do grupo (ERPSERV/BIZIFY)
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
        $companyId = $v['company_id'] ?? null; unset($v['company_id']); // não é fillable → seta à parte
        $v['slug'] = Str::slug($v['name']);
        $team = HelpDeskTeam::create($v);
        if ($companyId) { $team->company_id = (int) $companyId; $team->save(); } // empresa do grupo escolhida
        if ($members) $team->members()->sync($members);
        return response()->json(['data' => $team->load('members:id,name')], 201);
    }

    public function update(Request $request, HelpDeskTeam $team): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        $members = $v['member_ids'] ?? null; unset($v['member_ids']);
        $hasCompany = array_key_exists('company_id', $v); $companyId = $v['company_id'] ?? null; unset($v['company_id']);
        if (isset($v['name'])) $v['slug'] = Str::slug($v['name']);
        $team->update($v);
        if ($hasCompany) { $team->company_id = $companyId ? (int) $companyId : null; $team->save(); } // troca a empresa do grupo
        if ($members !== null) $team->members()->sync($members);
        return response()->json(['data' => $team->fresh()->load('members:id,name')]);
    }

    public function destroy(HelpDeskTeam $team): JsonResponse
    {
        $team->delete(); // soft delete
        return response()->json(null, 204);
    }
}
