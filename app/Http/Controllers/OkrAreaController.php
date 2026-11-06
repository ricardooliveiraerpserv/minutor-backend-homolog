<?php

namespace App\Http\Controllers;

use App\Models\OkrArea;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Áreas organizacionais do OKR. Leitura via okr.view; escrita via okr.manage.
 */
class OkrAreaController extends Controller
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
        $rows = OkrArea::with('responsavel:id,name')
            ->withCount('members')
            ->when($request->boolean('only_active'), fn ($q) => $q->active())
            ->orderBy('name')
            ->get()
            ->map(fn ($a) => $this->serialize($a));

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, int $area): JsonResponse
    {
        $a = OkrArea::with(['responsavel:id,name', 'members:id,name,email'])->findOrFail($area);
        return response()->json(['data' => $this->serialize($a, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $this->assertManage($request);
        $v = $this->validatePayload($request);

        $a = DB::transaction(function () use ($v, $u) {
            $a = OkrArea::create([
                'name' => $v['name'],
                'description' => $v['description'] ?? null,
                'responsavel_id' => $v['responsavel_id'] ?? null,
                'active' => $v['active'] ?? true,
                'created_by' => $u->id,
            ]);
            if (!empty($v['member_ids'])) {
                $a->members()->sync($v['member_ids']);
            }
            return $a;
        });

        $a->load(['responsavel:id,name', 'members:id,name,email']);
        return response()->json(['data' => $this->serialize($a, true)], 201);
    }

    public function update(Request $request, int $area): JsonResponse
    {
        $this->assertManage($request);
        $a = OkrArea::findOrFail($area);
        $v = $this->validatePayload($request, $a->id);

        DB::transaction(function () use ($a, $v) {
            $a->update([
                'name' => $v['name'],
                'description' => $v['description'] ?? null,
                'responsavel_id' => $v['responsavel_id'] ?? null,
                'active' => $v['active'] ?? $a->active,
            ]);
            if (array_key_exists('member_ids', $v)) {
                $a->members()->sync($v['member_ids'] ?? []);
            }
        });

        $a->load(['responsavel:id,name', 'members:id,name,email']);
        return response()->json(['data' => $this->serialize($a, true)]);
    }

    public function destroy(Request $request, int $area): JsonResponse
    {
        $this->assertManage($request);
        $a = OkrArea::withCount('objectives')->findOrFail($area);
        abort_if($a->objectives_count > 0, 422, 'Não é possível excluir uma área com objetivos cadastrados.');
        $a->delete();
        return response()->json(['ok' => true]);
    }

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:200',
                Rule::unique('okr_areas', 'name')->ignore($ignoreId)->whereNull('deleted_at'),
            ],
            'description' => 'nullable|string',
            'responsavel_id' => 'nullable|integer|exists:users,id',
            'active' => 'sometimes|boolean',
            'member_ids' => 'sometimes|array',
            'member_ids.*' => 'integer|exists:users,id',
        ], [
            'name.unique' => 'Já existe uma área com este nome.',
        ]);
    }

    private function serialize(OkrArea $a, bool $withMembers = false): array
    {
        $out = [
            'id' => $a->id,
            'name' => $a->name,
            'description' => $a->description,
            'responsavel_id' => $a->responsavel_id,
            'responsavel_name' => $a->responsavel?->name,
            'active' => $a->active,
            'members_count' => $a->members_count ?? $a->members()->count(),
        ];
        if ($withMembers) {
            $out['members'] = $a->members->map(fn ($m) => [
                'id' => $m->id, 'name' => $m->name, 'email' => $m->email,
            ])->values();
        }
        return $out;
    }
}
