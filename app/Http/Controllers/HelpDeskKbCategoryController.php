<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskKbCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Help Desk — Base de Conhecimento: cadastro de categorias (árvore). */
class HelpDeskKbCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cats = HelpDeskKbCategory::query()
            ->when(!$request->boolean('all'), fn ($q) => $q->where('active', true))
            ->withCount('articles')
            ->orderBy('sort_order')->orderBy('name')->get();
        return response()->json(['data' => $cats]);
    }

    private function rules(bool $creating): array
    {
        return [
            'parent_id'   => 'nullable|exists:helpdesk_kb_categories,id',
            'name'        => ($creating ? 'required' : 'sometimes') . '|string|max:140',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string|max:40',
            'visibility'  => 'nullable|in:customer,internal',
            'active'      => 'nullable|boolean',
            'sort_order'  => 'nullable|integer',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        $v['slug'] = Str::slug($v['name']);
        return response()->json(['data' => HelpDeskKbCategory::create($v)], 201);
    }

    public function update(Request $request, HelpDeskKbCategory $kbCategory): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        if (isset($v['name'])) $v['slug'] = Str::slug($v['name']);
        $kbCategory->update($v);
        return response()->json(['data' => $kbCategory->fresh()]);
    }

    public function destroy(HelpDeskKbCategory $kbCategory): JsonResponse
    {
        $kbCategory->delete();
        return response()->json(null, 204);
    }
}
