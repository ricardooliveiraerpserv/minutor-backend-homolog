<?php

namespace App\Http\Controllers;

use App\Models\OkrKeyResult;
use App\Models\OkrMonthlyResult;
use App\Models\OkrMonthlyTarget;
use App\Models\OkrPeriodClosure;
use App\Models\User;
use App\Services\OkrProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Resultados-chave (KRs) e distribuição de metas mensais. Leitura via okr.view;
 * escrita via okr.manage.
 */
class OkrKeyResultController extends Controller
{
    public function __construct(private OkrProgressService $progress) {}

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
        $rows = OkrKeyResult::with(['responsavel:id,name'])
            ->when($request->filled('objective_id'), fn ($q) => $q->where('okr_objective_id', (int) $request->objective_id))
            ->orderBy('id')
            ->get()
            ->map(fn ($kr) => $this->serialize($kr));

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, int $keyResult): JsonResponse
    {
        $kr = OkrKeyResult::with(['responsavel:id,name', 'monthlyTargets'])->findOrFail($keyResult);
        return response()->json(['data' => $this->serialize($kr, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $this->assertManage($request);
        $v = $this->validatePayload($request);

        $kr = OkrKeyResult::create(array_merge(
            collect($v)->except('targets')->all(),
            ['created_by' => $u->id]
        ));

        if (!empty($v['targets'])) {
            $this->syncTargets($kr, $v['targets']);
        }

        $kr->load(['responsavel:id,name', 'monthlyTargets']);
        return response()->json(['data' => $this->serialize($kr, true)], 201);
    }

    public function update(Request $request, int $keyResult): JsonResponse
    {
        $this->assertManage($request);
        $kr = OkrKeyResult::findOrFail($keyResult);
        $v = $this->validatePayload($request, true);

        $kr->update(collect($v)->except('targets')->all());

        if (array_key_exists('targets', $v)) {
            $this->syncTargets($kr, $v['targets'] ?? []);
        }

        $kr->load(['responsavel:id,name', 'monthlyTargets']);
        return response()->json(['data' => $this->serialize($kr, true)]);
    }

    public function destroy(Request $request, int $keyResult): JsonResponse
    {
        $this->assertManage($request);
        OkrKeyResult::findOrFail($keyResult)->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * Distribui as metas mensais do KR. mode=auto divide a meta anual nos 12 meses
     * do ciclo (acumulativo = soma == meta; demais = mesma meta por mês);
     * mode=manual recebe a lista de metas por competência.
     */
    public function saveTargets(Request $request, int $keyResult): JsonResponse
    {
        $this->assertManage($request);
        $kr = OkrKeyResult::with('objective.cycle')->findOrFail($keyResult);

        $v = $request->validate([
            'mode' => ['required', Rule::in(OkrKeyResult::DISTRIBUTION_MODES)],
            'targets' => 'required_if:mode,manual|array',
            'targets.*.year_month' => ['required_with:targets', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'targets.*.target_value' => 'nullable|numeric',
        ]);

        $year = $kr->objective?->cycle?->year;
        abort_if(!$year, 422, 'O ciclo do objetivo não tem ano definido.');

        if ($v['mode'] === 'auto') {
            $annual = (float) ($kr->target_value ?? 0);
            $values = $kr->aggregation === 'acumulativo'
                ? $this->progress->autoDistribute($annual)          // soma == meta anual
                : array_fill(0, 12, round($annual, 4));             // mesma meta por mês
            $targets = [];
            foreach ($values as $i => $val) {
                $targets[] = ['year_month' => sprintf('%04d-%02d', $year, $i + 1), 'target_value' => $val];
            }
        } else {
            $targets = $v['targets'];
        }

        $kr->update(['distribution_mode' => $v['mode']]);
        $this->syncTargets($kr, $targets);

        $kr->load('monthlyTargets');
        return response()->json(['data' => $this->serialize($kr, true)]);
    }

    /**
     * Grade de acompanhamento de UM KR: 12 meses (meta, realizado, acumulados,
     * status) + resumo (atingimento, aderência, semáforo). Somente leitura.
     */
    public function monthly(Request $request, int $keyResult): JsonResponse
    {
        $kr = OkrKeyResult::with(['monthlyTargets', 'monthlyResults.reporter:id,name', 'objective.cycle'])->findOrFail($keyResult);
        $series = $this->progress->seriesForKeyResult($kr);

        // Anexa os campos de check-in por mês ao grid.
        $resultsByMonth = [];
        foreach ($kr->monthlyResults as $r) {
            $resultsByMonth[(int) substr($r->year_month, 5, 2)] = $r;
        }
        $months = array_map(function ($row) use ($resultsByMonth) {
            $r = $resultsByMonth[$row['month']] ?? null;
            return array_merge($row, [
                'informed' => $r !== null && $r->actual_value !== null,
                'comment' => $r?->comment,
                'risks' => $r?->risks,
                'action_plan' => $r?->action_plan,
                'situation' => $r?->situation,
                'reported_by_name' => $r?->reporter?->name,
                'reported_at' => $r?->reported_at?->toIso8601String(),
            ]);
        }, $series['months']);

        return response()->json(['data' => [
            'key_result' => $this->serialize($kr),
            'months' => $months,
            'summary' => $series['summary'],
        ]]);
    }

    /**
     * Registra/atualiza o realizado + check-in de um mês. Pendente (null) nunca
     * é tratado como zero. Permissão okr.checkin (ou okr.manage) via rota.
     */
    public function saveResult(Request $request, int $keyResult): JsonResponse
    {
        $u = $request->user();
        $kr = OkrKeyResult::with('objective')->findOrFail($keyResult);

        $v = $request->validate([
            'year_month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'actual_value' => 'nullable|numeric',
            'comment' => 'nullable|string',
            'risks' => 'nullable|string',
            'action_plan' => 'nullable|string',
            'situation' => 'nullable|string|max:30',
            'reason' => 'nullable|string|max:255',
        ]);

        // Não permite alterar mês fechado (área do KR ou fechamento global).
        abort_if($this->isMonthClosed($kr, $v['year_month']), 422, 'Mês fechado: reabra o período para editar.');

        $r = OkrMonthlyResult::firstOrNew([
            'okr_key_result_id' => $kr->id,
            'year_month' => $v['year_month'],
        ]);
        $r->changeReason = $v['reason'] ?? null;
        $r->fill([
            'actual_value' => array_key_exists('actual_value', $v) ? $v['actual_value'] : $r->actual_value,
            'comment' => $v['comment'] ?? null,
            'risks' => $v['risks'] ?? null,
            'action_plan' => $v['action_plan'] ?? null,
            'situation' => $v['situation'] ?? null,
            'source' => 'manual',
            'reported_by' => $u->id,
            'reported_at' => now(),
        ]);
        $r->save();

        return response()->json(['data' => [
            'year_month' => $r->year_month,
            'actual_value' => $r->actual_value,
            'comment' => $r->comment,
            'risks' => $r->risks,
            'action_plan' => $r->action_plan,
            'situation' => $r->situation,
        ]]);
    }

    /** Mês fechado para o KR? Checa fechamento da área do objetivo OU global (area null). */
    private function isMonthClosed(OkrKeyResult $kr, string $yearMonth): bool
    {
        $cycleId = $kr->objective?->okr_cycle_id;
        if (!$cycleId) {
            return false;
        }
        $areaId = $kr->objective?->okr_area_id;
        return OkrPeriodClosure::where('okr_cycle_id', $cycleId)
            ->where('year_month', $yearMonth)
            ->where('status', 'fechado')
            ->where(function ($q) use ($areaId) {
                $q->whereNull('okr_area_id');
                if ($areaId) {
                    $q->orWhere('okr_area_id', $areaId);
                }
            })
            ->exists();
    }

    /** Substitui todas as metas mensais do KR (sync completo) numa transação. */
    private function syncTargets(OkrKeyResult $kr, array $targets): void
    {
        DB::transaction(function () use ($kr, $targets) {
            $kr->monthlyTargets()->delete();
            $seen = [];
            foreach ($targets as $t) {
                $ym = $t['year_month'];
                if (isset($seen[$ym])) {
                    continue; // uma meta por competência
                }
                $seen[$ym] = true;
                OkrMonthlyTarget::create([
                    'okr_key_result_id' => $kr->id,
                    'year_month' => $ym,
                    'target_value' => $t['target_value'] ?? null,
                ]);
            }
        });
    }

    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'okr_objective_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:okr_objectives,id'],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => 'nullable|string',
            'responsavel_id' => 'nullable|integer|exists:users,id',
            'unit' => ['sometimes', Rule::in(OkrKeyResult::UNITS)],
            'unit_label' => 'nullable|string|max:50',
            'direction' => ['sometimes', Rule::in(OkrKeyResult::DIRECTIONS)],
            'aggregation' => ['sometimes', Rule::in(OkrKeyResult::AGGREGATIONS)],
            'baseline_value' => 'nullable|numeric',
            'target_value' => 'nullable|numeric',
            'band_min' => 'nullable|numeric',
            'band_max' => 'nullable|numeric|gte:band_min',
            'weight' => 'nullable|numeric|min:0',
            'distribution_mode' => ['sometimes', Rule::in(OkrKeyResult::DISTRIBUTION_MODES)],
            'data_source_type' => ['sometimes', Rule::in(OkrKeyResult::DATA_SOURCE_TYPES)],
            'data_source_key' => 'nullable|string|max:100',
            'status' => ['sometimes', Rule::in(OkrKeyResult::STATUSES)],
            'targets' => 'sometimes|array',
            'targets.*.year_month' => ['required_with:targets', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'targets.*.target_value' => 'nullable|numeric',
        ]);
    }

    private function serialize(OkrKeyResult $kr, bool $withTargets = false): array
    {
        $out = [
            'id' => $kr->id,
            'okr_objective_id' => $kr->okr_objective_id,
            'name' => $kr->name,
            'description' => $kr->description,
            'responsavel_id' => $kr->responsavel_id,
            'responsavel_name' => $kr->responsavel?->name,
            'unit' => $kr->unit,
            'unit_label' => $kr->unit_label,
            'direction' => $kr->direction,
            'aggregation' => $kr->aggregation,
            'baseline_value' => $kr->baseline_value,
            'target_value' => $kr->target_value,
            'band_min' => $kr->band_min,
            'band_max' => $kr->band_max,
            'weight' => $kr->weight,
            'distribution_mode' => $kr->distribution_mode,
            'data_source_type' => $kr->data_source_type,
            'data_source_key' => $kr->data_source_key,
            'status' => $kr->status,
        ];
        if ($withTargets) {
            $out['monthly_targets'] = $kr->monthlyTargets
                ->sortBy('year_month')
                ->map(fn ($t) => ['year_month' => $t->year_month, 'target_value' => $t->target_value])
                ->values();
        }
        return $out;
    }
}
