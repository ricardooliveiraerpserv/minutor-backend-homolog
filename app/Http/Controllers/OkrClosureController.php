<?php

namespace App\Http\Controllers;

use App\Models\OkrPeriodClosure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Fechamento mensal por área (ou global). Fechar exige okr.close; reabrir exige
 * okr.reopen + justificativa. Meses fechados bloqueiam a edição do realizado
 * (checado em OkrKeyResultController@saveResult).
 */
class OkrClosureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'cycle_id' => 'required|integer|exists:okr_cycles,id',
            'year_month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $rows = OkrPeriodClosure::with(['area:id,name', 'closedBy:id,name', 'reopenedBy:id,name'])
            ->where('okr_cycle_id', $v['cycle_id'])
            ->when(!empty($v['year_month']), fn ($q) => $q->where('year_month', $v['year_month']))
            ->get()
            ->map(fn ($c) => $this->serialize($c));

        return response()->json(['data' => $rows]);
    }

    public function close(Request $request): JsonResponse
    {
        $u = $request->user();
        $v = $request->validate([
            'cycle_id' => 'required|integer|exists:okr_cycles,id',
            'okr_area_id' => 'nullable|integer|exists:okr_areas,id',
            'year_month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'status' => ['sometimes', Rule::in(['em_revisao', 'fechado'])],
        ]);

        $c = OkrPeriodClosure::firstOrNew([
            'okr_cycle_id' => $v['cycle_id'],
            'okr_area_id' => $v['okr_area_id'] ?? null,
            'year_month' => $v['year_month'],
        ]);
        $status = $v['status'] ?? 'fechado';
        $c->status = $status;
        if ($status === 'fechado') {
            $c->closed_by = $u->id;
            $c->closed_at = now();
        }
        $c->save();

        return response()->json(['data' => $this->serialize($c->fresh(['area', 'closedBy', 'reopenedBy']))], $c->wasRecentlyCreated ? 201 : 200);
    }

    public function reopen(Request $request): JsonResponse
    {
        $u = $request->user();
        $v = $request->validate([
            'cycle_id' => 'required|integer|exists:okr_cycles,id',
            'okr_area_id' => 'nullable|integer|exists:okr_areas,id',
            'year_month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'reason' => 'required|string|max:500',
        ]);

        $c = OkrPeriodClosure::where('okr_cycle_id', $v['cycle_id'])
            ->where('okr_area_id', $v['okr_area_id'] ?? null)
            ->where('year_month', $v['year_month'])
            ->firstOrFail();

        abort_unless($c->isClosed(), 422, 'O período não está fechado.');

        // Preserva quem/quando fechou; registra a reabertura.
        $c->update([
            'status' => 'aberto',
            'reopened_by' => $u->id,
            'reopened_at' => now(),
            'reopen_reason' => $v['reason'],
        ]);

        return response()->json(['data' => $this->serialize($c->fresh(['area', 'closedBy', 'reopenedBy']))]);
    }

    private function serialize(OkrPeriodClosure $c): array
    {
        return [
            'id' => $c->id,
            'okr_cycle_id' => $c->okr_cycle_id,
            'okr_area_id' => $c->okr_area_id,
            'area_name' => $c->area?->name,
            'year_month' => $c->year_month,
            'status' => $c->status,
            'closed_by_name' => $c->closedBy?->name,
            'closed_at' => $c->closed_at?->toIso8601String(),
            'reopened_by_name' => $c->reopenedBy?->name,
            'reopened_at' => $c->reopened_at?->toIso8601String(),
            'reopen_reason' => $c->reopen_reason,
        ];
    }
}
