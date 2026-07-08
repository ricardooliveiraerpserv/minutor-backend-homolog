<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Help Desk — Catálogo de Serviços (árvore). CRUD de gestão. */
class HelpDeskServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $services = HelpDeskService::query()
            ->when(!$request->boolean('all'), fn ($q) => $q->where('active', true))
            ->orderBy('sort_order')->orderBy('name')
            ->get();
        return response()->json(['data' => $services]);
    }

    private function rules(bool $creating): array
    {
        return [
            'parent_id'            => 'nullable|exists:helpdesk_services,id',
            'name'                 => ($creating ? 'required' : 'sometimes') . '|string|max:160',
            'code'                 => 'nullable|string|max:40',
            'availability'         => 'nullable|in:public_and_internal,public,internal',
            'visible_to_agent'     => 'nullable|boolean',
            'visible_to_client'    => 'nullable|boolean',
            'selectable_by_agent'  => 'nullable|boolean',
            'selectable_by_client' => 'nullable|boolean',
            'allow_conclusion'     => 'nullable|boolean',
            'active'               => 'nullable|boolean',
            'sort_order'           => 'nullable|integer',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        return response()->json(['data' => HelpDeskService::create($v)], 201);
    }

    public function update(Request $request, HelpDeskService $service): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        // Evita ciclo: serviço não pode ser pai de si mesmo.
        if (($v['parent_id'] ?? null) == $service->id) unset($v['parent_id']);
        $service->update($v);
        return response()->json(['data' => $service->fresh()]);
    }

    public function destroy(HelpDeskService $service): JsonResponse
    {
        $service->delete(); // soft delete
        return response()->json(null, 204);
    }
}
