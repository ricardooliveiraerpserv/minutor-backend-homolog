<?php

namespace App\Http\Controllers;

use App\Models\FollowUpCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cadastro de categorias de Follow Up. Compatível com o IsActiveCrudTab do /cadastros
 * (index {items,hasNext}; store/update/destroy com name/code/description/is_active).
 */
class FollowUpCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = FollowUpCategory::query();
        if ($request->filled('search')) $q->where('name', 'ilike', '%' . $request->get('search') . '%');
        if ($request->filled('filter_status')) $q->where('is_active', $request->get('filter_status') === 'active');

        $q->orderBy('sort_order')->orderBy('name');
        $pageSize = min((int) $request->get('pageSize', 200), 500);
        $page = $q->paginate($pageSize);

        return response()->json(['items' => $page->items(), 'hasNext' => $page->hasMorePages()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request, true);
        $data['sort_order'] = (int) FollowUpCategory::max('sort_order') + 1;
        return response()->json(FollowUpCategory::create($data), 201);
    }

    public function update(Request $request, FollowUpCategory $followUpCategory): JsonResponse
    {
        $followUpCategory->update($this->validateData($request, false));
        return response()->json($followUpCategory);
    }

    public function destroy(FollowUpCategory $followUpCategory): JsonResponse
    {
        $followUpCategory->delete();
        return response()->json(['deleted' => true]);
    }

    private function validateData(Request $request, bool $creating): array
    {
        return $request->validate([
            'name'        => ($creating ? 'required' : 'sometimes') . '|string|max:120|unique:follow_up_categories,name' . ($creating ? '' : ',' . optional($request->route('followUpCategory'))->id),
            'code'        => 'nullable|string|max:60',
            'description' => 'nullable|string|max:255',
            'is_active'   => 'nullable|boolean',
        ]);
    }
}
