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
        return response()->json(['data' => $policy->load(['targets.pauses', 'holidays'])]);
    }

    private function rules(bool $creating): array
    {
        return [
            'name'                  => ($creating ? 'required' : 'sometimes') . '|string|max:140',
            'description'           => 'nullable|string',
            'customer_id'           => 'nullable|exists:customers,id',
            'contract_id'           => 'nullable|exists:contracts,id',
            'business_hours'        => 'nullable|array',
            'timezone'              => 'nullable|string|max:40',
            'use_national_holidays' => 'nullable|boolean',
            'is_default'            => 'nullable|boolean',
            'active'                => 'nullable|boolean',
            // Metas por prioridade (upsert): [{priority, first_response_minutes, resolution_minutes, pauses:[status_key]}]
            'targets'                          => 'nullable|array',
            'targets.*.priority'               => 'required|in:' . implode(',', HelpDeskTicket::PRIORITIES),
            'targets.*.first_response_minutes' => 'nullable|integer|min:0',
            'targets.*.resolution_minutes'     => 'nullable|integer|min:0',
            'targets.*.pauses'                 => 'nullable|array',       // status que pausam ESTA regra
            'targets.*.pauses.*'               => 'string|max:60',
            // Feriados por contrato: [{date, name}]
            'holidays'                         => 'nullable|array',
            'holidays.*.date'                  => 'required|date',
            'holidays.*.name'                  => 'nullable|string|max:120',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        $targets = $v['targets'] ?? []; unset($v['targets']);
        $holidays = $v['holidays'] ?? null; unset($v['holidays']);
        return DB::transaction(function () use ($v, $targets, $holidays) {
            if (!empty($v['is_default'])) HelpDeskSlaPolicy::where('is_default', true)->update(['is_default' => false]);
            $policy = HelpDeskSlaPolicy::create($v);
            $this->upsertTargets($policy, $targets);
            if ($holidays !== null) $this->syncHolidays($policy, $holidays);
            return response()->json(['data' => $policy->load(['targets.pauses', 'holidays'])], 201);
        });
    }

    public function update(Request $request, HelpDeskSlaPolicy $policy): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        $targets = $v['targets'] ?? null; unset($v['targets']);
        $holidays = $v['holidays'] ?? null; unset($v['holidays']);
        return DB::transaction(function () use ($v, $targets, $holidays, $policy) {
            if (!empty($v['is_default'])) HelpDeskSlaPolicy::where('id', '!=', $policy->id)->where('is_default', true)->update(['is_default' => false]);
            $policy->update($v);
            if ($targets !== null) $this->upsertTargets($policy, $targets);
            if ($holidays !== null) $this->syncHolidays($policy, $holidays);
            return response()->json(['data' => $policy->fresh()->load(['targets.pauses', 'holidays'])]);
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
            $target = $policy->targets()->updateOrCreate(
                ['priority' => $t['priority']],
                [
                    'first_response_minutes' => $t['first_response_minutes'] ?? null,
                    'resolution_minutes'     => $t['resolution_minutes'] ?? null,
                ]
            );
            // Pausas POR REGRA (substitui a lista inteira quando 'pauses' vier no payload).
            if (array_key_exists('pauses', $t)) {
                $target->pauses()->delete();
                foreach (array_unique($t['pauses'] ?? []) as $key) {
                    $target->pauses()->create(['status_key' => $key]);
                }
            }
        }
    }

    /** Substitui a lista de feriados específicos da política. */
    private function syncHolidays(HelpDeskSlaPolicy $policy, array $holidays): void
    {
        $policy->holidays()->delete();
        $seen = [];
        foreach ($holidays as $h) {
            $date = \Carbon\Carbon::parse($h['date'])->format('Y-m-d');
            if (isset($seen[$date])) continue;
            $seen[$date] = true;
            $policy->holidays()->create(['date' => $date, 'name' => $h['name'] ?? null]);
        }
    }
}
