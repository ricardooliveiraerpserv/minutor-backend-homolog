<?php

namespace App\Http\Controllers;

use App\Models\CrmCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CRM — cadastro de Campanhas comerciais (Cadastros CRM › Campanhas). */
class CrmCampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = CrmCampaign::query();
        // Por padrão o cadastro lista tudo; o vínculo em oportunidades usa ?active=1.
        if ($request->boolean('active')) {
            $q->where('active', true);
        }
        return response()->json(['data' => $q->orderByDesc('active')->orderByDesc('starts_at')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $this->validated($request);
        return response()->json(['data' => CrmCampaign::create($v + ['active' => $request->boolean('active', true)])], 201);
    }

    public function update(Request $request, CrmCampaign $campaign): JsonResponse
    {
        $v = $this->validated($request, $campaign->id);
        if ($request->has('active')) {
            $v['active'] = $request->boolean('active');
        }
        $campaign->update($v);
        return response()->json(['data' => $campaign]);
    }

    public function destroy(CrmCampaign $campaign): JsonResponse
    {
        // FK em oportunidades é nullOnDelete — apagar solta o vínculo sem quebrar.
        $campaign->delete();
        return response()->json(null, 204);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name'      => 'required|string|max:120',
            'starts_at' => 'nullable|date',
            'ends_at'   => 'nullable|date|after_or_equal:starts_at',
        ]);
    }
}
