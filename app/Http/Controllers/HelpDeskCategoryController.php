<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Help Desk — Cadastro de categorias de chamado (árvore). */
class HelpDeskCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cats = HelpDeskCategory::query()
            ->when(!$request->boolean('all'), fn ($q) => $q->where('active', true))
            ->orderBy('sort_order')->orderBy('name')
            ->get();
        return response()->json(['data' => $cats]);
    }

    private function rules(bool $creating): array
    {
        return [
            'parent_id'       => 'nullable|exists:helpdesk_categories,id',
            'name'            => ($creating ? 'required' : 'sometimes') . '|string|max:120',
            'description'     => 'nullable|string',
            'color'           => 'nullable|string|max:16',
            'default_team_id' => 'nullable|exists:helpdesk_teams,id',
            'sla_policy_id'   => 'nullable|exists:helpdesk_sla_policies,id',
            'active'          => 'nullable|boolean',
            'sort_order'      => 'nullable|integer',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        $v['slug'] = Str::slug($v['name']);
        return response()->json(['data' => HelpDeskCategory::create($v)], 201);
    }

    public function update(Request $request, HelpDeskCategory $category): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        if (isset($v['name'])) $v['slug'] = Str::slug($v['name']);
        $category->update($v);
        return response()->json(['data' => $category->fresh()]);
    }

    public function destroy(HelpDeskCategory $category): JsonResponse
    {
        $category->delete(); // soft delete
        return response()->json(null, 204);
    }
}
