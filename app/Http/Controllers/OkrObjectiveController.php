<?php

namespace App\Http\Controllers;

use App\Models\OkrObjective;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Objetivos do OKR (corporativos e de área). Leitura via okr.view; escrita via okr.manage.
 */
class OkrObjectiveController extends Controller
{
    private function canManage(User $u): bool
    {
        return $u->isAdmin() || $u->isCoordenador() || $u->hasAccess('okr.manage');
    }

    private function assertManage(Request $request): User
    {
        $u = $request->user();
        abort_unless($u && $this->canManage($u), 403, 'Sem permissão para gerenciar OKRs.');
        return $u;
    }

    public function index(Request $request): JsonResponse
    {
        $rows = OkrObjective::with(['area:id,name', 'responsavel:id,name', 'parent:id,title'])
            ->withCount('keyResults')
            ->when($request->filled('cycle_id'), fn ($q) => $q->where('okr_cycle_id', (int) $request->cycle_id))
            ->when($request->filled('area_id'), fn ($q) => $q->where('okr_area_id', (int) $request->area_id))
            ->when($request->filled('level'), fn ($q) => $q->where('level', $request->level))
            ->orderBy('level')
            ->orderBy('title')
            ->get()
            ->map(fn ($o) => $this->serialize($o));

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, int $objective): JsonResponse
    {
        $o = OkrObjective::with(['area:id,name', 'responsavel:id,name', 'parent:id,title', 'children:id,title,okr_area_id'])
            ->withCount('keyResults')
            ->findOrFail($objective);
        return response()->json(['data' => $this->serialize($o)]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $this->assertManage($request);
        $v = $this->validatePayload($request);
        $v = $this->normalizeLevel($v);

        $o = OkrObjective::create(array_merge($v, ['created_by' => $u->id]));
        $o->load(['area:id,name', 'responsavel:id,name', 'parent:id,title'])->loadCount('keyResults');

        return response()->json(['data' => $this->serialize($o)], 201);
    }

    public function update(Request $request, int $objective): JsonResponse
    {
        $this->assertManage($request);
        $o = OkrObjective::findOrFail($objective);
        $v = $this->validatePayload($request, $o);
        $v = $this->normalizeLevel($v);

        $o->update($v);
        $o->load(['area:id,name', 'responsavel:id,name', 'parent:id,title'])->loadCount('keyResults');

        return response()->json(['data' => $this->serialize($o)]);
    }

    public function destroy(Request $request, int $objective): JsonResponse
    {
        $this->assertManage($request);
        $o = OkrObjective::findOrFail($objective);
        $o->delete(); // cascade (soft) nos KRs via FK + soft delete do objetivo
        return response()->json(['ok' => true]);
    }

    private function validatePayload(Request $request, ?OkrObjective $existing = null): array
    {
        $cycleId = $existing?->okr_cycle_id ?? $request->input('okr_cycle_id');

        return $request->validate([
            'okr_cycle_id' => [$existing ? 'sometimes' : 'required', 'integer', 'exists:okr_cycles,id'],
            'level' => ['required', Rule::in(OkrObjective::LEVELS)],
            'okr_area_id' => ['nullable', 'integer', 'exists:okr_areas,id', Rule::requiredIf(fn () => $request->input('level') === 'area')],
            'parent_objective_id' => [
                'nullable', 'integer',
                // O pai precisa ser um objetivo corporativo do MESMO ciclo.
                Rule::exists('okr_objectives', 'id')->where(fn ($q) => $q
                    ->where('level', 'corporativo')
                    ->when($cycleId, fn ($qq) => $qq->where('okr_cycle_id', $cycleId))),
            ],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'responsavel_id' => 'nullable|integer|exists:users,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'priority' => ['sometimes', Rule::in(OkrObjective::PRIORITIES)],
            'status' => ['sometimes', Rule::in(OkrObjective::STATUSES)],
            'weight' => 'nullable|numeric|min:0',
        ], [
            'okr_area_id.required' => 'Objetivo de área exige uma área.',
            'parent_objective_id.exists' => 'O objetivo corporativo vinculado é inválido para este ciclo.',
        ]);
    }

    /** Corporativo não tem área nem pai; garante consistência. */
    private function normalizeLevel(array $v): array
    {
        if (($v['level'] ?? null) === 'corporativo') {
            $v['okr_area_id'] = null;
            $v['parent_objective_id'] = null;
        }
        return $v;
    }

    private function serialize(OkrObjective $o): array
    {
        return [
            'id' => $o->id,
            'okr_cycle_id' => $o->okr_cycle_id,
            'level' => $o->level,
            'okr_area_id' => $o->okr_area_id,
            'area_name' => $o->area?->name,
            'parent_objective_id' => $o->parent_objective_id,
            'parent_title' => $o->parent?->title,
            'title' => $o->title,
            'description' => $o->description,
            'responsavel_id' => $o->responsavel_id,
            'responsavel_name' => $o->responsavel?->name,
            'start_date' => $o->start_date?->toDateString(),
            'end_date' => $o->end_date?->toDateString(),
            'priority' => $o->priority,
            'status' => $o->status,
            'weight' => $o->weight,
            'key_results_count' => $o->key_results_count ?? 0,
            'children' => $o->relationLoaded('children')
                ? $o->children->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'okr_area_id' => $c->okr_area_id])->values()
                : null,
        ];
    }
}
