<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskSlaPolicy;
use App\Models\HelpDeskTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Help Desk — Cadastro de políticas de SLA + metas por prioridade. */
class HelpDeskSlaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $policies = HelpDeskSlaPolicy::query()
            ->when(!$request->boolean('all'), fn ($q) => $q->where('active', true))
            ->with(['targets', 'customer:id,name', 'contract:id'])
            ->orderByDesc('is_default')->orderBy('name')->get();
        return response()->json(['data' => $policies]);
    }

    public function show(HelpDeskSlaPolicy $policy): JsonResponse
    {
        return response()->json(['data' => $policy->load('targets')]);
    }

    private function rules(bool $creating): array
    {
        return [
            'name'           => ($creating ? 'required' : 'sometimes') . '|string|max:140',
            'description'    => 'nullable|string',
            'customer_id'    => 'nullable|exists:customers,id',
            'contract_id'    => 'nullable|exists:contracts,id',
            'business_hours' => 'nullable|array',
            'is_default'     => 'nullable|boolean',
            'active'         => 'nullable|boolean',
            // Metas por prioridade (upsert): [{priority, first_response_minutes, resolution_minutes}]
            'targets'                          => 'nullable|array',
            'targets.*.priority'               => 'required|in:' . implode(',', HelpDeskTicket::PRIORITIES),
            'targets.*.first_response_minutes' => 'nullable|integer|min:0',
            'targets.*.resolution_minutes'     => 'nullable|integer|min:0',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        $targets = $v['targets'] ?? []; unset($v['targets']);
        return DB::transaction(function () use ($v, $targets) {
            if (!empty($v['is_default'])) HelpDeskSlaPolicy::where('is_default', true)->update(['is_default' => false]);
            $policy = HelpDeskSlaPolicy::create($v);
            $this->upsertTargets($policy, $targets);
            return response()->json(['data' => $policy->load('targets')], 201);
        });
    }

    public function update(Request $request, HelpDeskSlaPolicy $policy): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        $targets = $v['targets'] ?? null; unset($v['targets']);
        return DB::transaction(function () use ($v, $targets, $policy) {
            if (!empty($v['is_default'])) HelpDeskSlaPolicy::where('id', '!=', $policy->id)->where('is_default', true)->update(['is_default' => false]);
            $policy->update($v);
            if ($targets !== null) $this->upsertTargets($policy, $targets);
            return response()->json(['data' => $policy->fresh()->load('targets')]);
        });
    }

    public function destroy(HelpDeskSlaPolicy $policy): JsonResponse
    {
        abort_if($policy->is_default, 422, 'Não é possível excluir a política padrão.');
        $policy->delete(); // soft delete
        return response()->json(null, 204);
    }

    private function upsertTargets(HelpDeskSlaPolicy $policy, array $targets): void
    {
        foreach ($targets as $t) {
            $policy->targets()->updateOrCreate(
                ['priority' => $t['priority']],
                [
                    'first_response_minutes' => $t['first_response_minutes'] ?? null,
                    'resolution_minutes'     => $t['resolution_minutes'] ?? null,
                ]
            );
        }
    }
}
