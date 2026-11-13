<?php

namespace App\Http\Controllers;

use App\Models\OkrCycle;
use App\Models\OkrKeyResult;
use App\Models\OkrMonthlyTarget;
use App\Models\OkrObjective;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Planejamento Anual (ciclos de OKR). Leitura liberada pelo gate okr.view (grupo
 * de rotas); escrita exige okr.manage (admin/coordenador passam direto).
 */
class OkrCycleController extends Controller
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
        $rows = OkrCycle::with('responsavel:id,name')
            ->withCount('objectives')
            ->when($request->filled('year'), fn ($q) => $q->where('year', (int) $request->year))
            ->orderByDesc('year')
            ->get()
            ->map(fn ($c) => $this->serialize($c));

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, int $cycle): JsonResponse
    {
        $c = OkrCycle::with('responsavel:id,name')->withCount('objectives')->findOrFail($cycle);
        return response()->json(['data' => $this->serialize($c)]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $this->assertManage($request);
        $v = $this->validatePayload($request);

        $c = OkrCycle::create(array_merge($v, ['created_by' => $u->id]));
        $c->load('responsavel:id,name')->loadCount('objectives');

        return response()->json(['data' => $this->serialize($c)], 201);
    }

    public function update(Request $request, int $cycle): JsonResponse
    {
        $this->assertManage($request);
        $c = OkrCycle::findOrFail($cycle);
        $v = $this->validatePayload($request, $c->id);

        $c->update($v);
        $c->load('responsavel:id,name')->loadCount('objectives');

        return response()->json(['data' => $this->serialize($c)]);
    }

    public function destroy(Request $request, int $cycle): JsonResponse
    {
        $this->assertManage($request);
        $c = OkrCycle::findOrFail($cycle);
        $c->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * Copia a ESTRUTURA de um ciclo (objetivos + KRs + metas mensais) para um novo
     * ano. NÃO copia realizações nem check-ins (ainda não existem na Fase 1).
     */
    public function copy(Request $request, int $cycle): JsonResponse
    {
        $u = $this->assertManage($request);
        $source = OkrCycle::with('objectives.keyResults.monthlyTargets')->findOrFail($cycle);

        $v = $request->validate([
            'year' => [
                'required', 'integer', 'min:2000', 'max:2100',
                Rule::unique('okr_cycles', 'year')
                    ->where(fn ($q) => $q->where('company_id', $source->company_id))
                    ->whereNull('deleted_at'),
            ],
            'name' => 'nullable|string|max:200',
        ], [
            'year.unique' => 'Já existe um planejamento para este ano.',
        ]);

        $new = DB::transaction(function () use ($source, $v, $u) {
            $cycle = OkrCycle::create([
                'company_id' => $source->company_id,
                'year' => $v['year'],
                'name' => $v['name'] ?? ($source->name . ' (' . $v['year'] . ')'),
                'description' => $source->description,
                'responsavel_id' => $source->responsavel_id,
                'status' => 'rascunho',
                'settings' => $source->settings,
                'created_by' => $u->id,
            ]);

            // Mapa objetivo-antigo => objetivo-novo para religar parent_objective_id.
            $map = [];
            foreach ($source->objectives as $obj) {
                $copy = $obj->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
                $copy->okr_cycle_id = $cycle->id;
                $copy->parent_objective_id = null; // religado abaixo
                $copy->status = 'planejado';
                $copy->created_by = $u->id;
                $copy->save();
                $map[$obj->id] = $copy->id;
            }
            // Religar desdobramentos.
            foreach ($source->objectives as $obj) {
                if ($obj->parent_objective_id && isset($map[$obj->parent_objective_id])) {
                    OkrObjective::where('id', $map[$obj->id])
                        ->update(['parent_objective_id' => $map[$obj->parent_objective_id]]);
                }
            }
            // KRs + metas mensais.
            foreach ($source->objectives as $obj) {
                foreach ($obj->keyResults as $kr) {
                    $krCopy = $kr->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
                    $krCopy->okr_objective_id = $map[$obj->id];
                    $krCopy->status = 'planejado';
                    $krCopy->created_by = $u->id;
                    $krCopy->save();

                    foreach ($kr->monthlyTargets as $mt) {
                        // Recompõe year_month trocando o ano de origem pelo novo.
                        $newYm = $v['year'] . substr($mt->year_month, 4);
                        OkrMonthlyTarget::create([
                            'okr_key_result_id' => $krCopy->id,
                            'year_month' => $newYm,
                            'target_value' => $mt->target_value,
                        ]);
                    }
                }
            }

            return $cycle;
        });

        $new->load('responsavel:id,name')->loadCount('objectives');
        return response()->json(['data' => $this->serialize($new)], 201);
    }

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        // "Ano único" é POR EMPRESA (índice (company_id, year)), igual à cópia: na edição vale a
        // empresa do próprio ciclo; na criação, a empresa ativa (a mesma que o BelongsToCompany
        // carimba). Sem empresa resolvida (scoping desligado), mantém a checagem global.
        $companyId = $ignoreId
            ? OkrCycle::withoutGlobalScopes()->whereKey($ignoreId)->value('company_id')
            : (config('multiempresa.scoping_enabled') ? app(\App\Services\CompanyContext::class)->id() : null);

        $unique = Rule::unique('okr_cycles', 'year')
            ->ignore($ignoreId)
            ->whereNull('deleted_at');
        if ($companyId) {
            $unique = $unique->where(fn ($q) => $q->where('company_id', $companyId));
        }

        return $request->validate([
            'year' => [
                'required', 'integer', 'min:2000', 'max:2100',
                $unique,
            ],
            'name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'responsavel_id' => 'nullable|integer|exists:users,id',
            'start_date' => ['nullable', 'date', $this->withinCycleYear($request, 'início')],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', $this->withinCycleYear($request, 'término')],
            'status' => ['sometimes', Rule::in(OkrCycle::STATUSES)],
            'settings' => 'nullable|array',
        ], [
            'year.unique' => 'Já existe um planejamento para este ano.',
            'end_date.after_or_equal' => 'O término deve ser igual ou posterior ao início.',
        ]);
    }

    /** O período do planejamento precisa cair dentro do ano do ciclo (ex.: ciclo 2027 → datas de 2027). */
    private function withinCycleYear(Request $request, string $label): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($request, $label) {
            $year = (int) $request->input('year');
            if ($value && $year && (int) substr((string) $value, 0, 4) !== $year) {
                $fail("A data de {$label} deve estar dentro do ano do planejamento ({$year}).");
            }
        };
    }

    private function serialize(OkrCycle $c): array
    {
        return [
            'id' => $c->id,
            'year' => $c->year,
            'name' => $c->name,
            'description' => $c->description,
            'status' => $c->status,
            'responsavel_id' => $c->responsavel_id,
            'responsavel_name' => $c->responsavel?->name,
            'start_date' => $c->start_date?->toDateString(),
            'end_date' => $c->end_date?->toDateString(),
            'settings' => $c->settings,
            'objectives_count' => $c->objectives_count ?? 0,
            'approved_at' => $c->approved_at?->toIso8601String(),
            'closed_at' => $c->closed_at?->toIso8601String(),
        ];
    }
}
