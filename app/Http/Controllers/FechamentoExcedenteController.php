<?php

namespace App\Http\Controllers;

use App\Models\ExcessHourCharge;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fechamento de HORAS EXCEDENTES (BH Mensal / BH Fixo).
 *
 * Rotina do administrativo pra cobrar horas consumidas acima das contratadas.
 *  - BH Mensal: excedente por COMPETÊNCIA (consumo do mês − contratadas do mês).
 *  - BH Fixo:   excedente pelo ESTADO ATUAL (saldo negativo), incremental sobre o
 *               que já foi cobrado (não recobra o mesmo excedente).
 * Valor = horas excedentes × Hora Adicional (additional_hourly_rate). Flag
 * charge_excess_hours (projeto) liga/desliga a cobrança; ajustável na rotina.
 */
class FechamentoExcedenteController extends Controller
{
    /** Projetos PAI de BH Mensal ou BH Fixo (não-investimento). */
    private function baseQuery()
    {
        return Project::query()
            ->with(['customer:id,name', 'contractType:id,name,code', 'hourlyRateChanges', 'soldHoursHistory',
                     'childProjects.contractType', 'hourContributions'])
            ->whereNull('parent_project_id')
            ->where('is_investimento_comercial', false)
            ->whereHas('contractType', function ($q) {
                $q->whereIn('code', ['monthly_hours', 'fixed_hours'])
                  ->orWhereRaw('lower(name) in (?, ?)', ['banco de horas mensal', 'banco de horas fixo']);
            });
    }

    /**
     * GET /fechamento-excedente?year_month=AAAA-MM
     * Lista os projetos com horas excedentes a cobrar na competência.
     */
    public function index(Request $request): JsonResponse
    {
        $yearMonth = $request->query('year_month');
        if (!$yearMonth) {
            return response()->json(['data' => [], 'total_geral' => 0]);
        }

        $projects = $this->baseQuery()->get();

        // Total já cobrado por projeto (para o incremental do BH Fixo).
        $charged = ExcessHourCharge::where('status', ExcessHourCharge::STATUS_COBRADO)
            ->whereIn('project_id', $projects->pluck('id'))
            ->get()
            ->groupBy('project_id')
            ->map(fn ($g) => (float) $g->sum('excess_hours'));

        // Registro persistido desta competência (status já definido na rotina).
        $records = ExcessHourCharge::where('year_month', $yearMonth)
            ->whereIn('project_id', $projects->pluck('id'))
            ->get()
            ->keyBy('project_id');

        $data = $projects->map(function (Project $p) use ($yearMonth, $charged, $records) {
            $ap   = $p->excessHoursApuracao($yearMonth);
            $rate = (float) ($p->additional_hourly_rate ?? 0);
            $rec  = $records->get($p->id);

            // Excedente PENDENTE a cobrar nesta competência.
            if ($ap['basis'] === 'fixed') {
                // Incremental: excedente atual − já cobrado (em qualquer competência).
                $jaCobrado = (float) ($charged->get($p->id) ?? 0);
                $excessPend = max(0, round($ap['excess'] - $jaCobrado, 2));
            } else {
                // Mensal: a competência é fechada; se já cobrado/marcado, o registro manda.
                $excessPend = $rec && in_array($rec->status, [ExcessHourCharge::STATUS_COBRADO, ExcessHourCharge::STATUS_NAO_COBRAR])
                    ? (float) $rec->excess_hours
                    : $ap['excess'];
            }

            $excess = $rec ? (float) $rec->excess_hours : $excessPend;
            $status = $rec?->status ?? ExcessHourCharge::STATUS_PENDENTE;

            return [
                'project_id'      => $p->id,
                'code'            => $p->code,
                'project_name'    => $p->name,
                'customer_id'     => $p->customer_id,
                'customer_name'   => $p->customer?->name ?? '—',
                'basis'           => $ap['basis'],           // monthly | fixed
                'contracted_hours'=> $ap['contracted'],
                'consumed_hours'  => $ap['consumed'],
                'excess_hours'    => round($excess, 2),
                'additional_hourly_rate' => round($rate, 2),
                'excess_value'    => round($excess * $rate, 2),
                'charge'          => (bool) $p->charge_excess_hours, // flag do contrato/projeto
                'status'          => $status,                 // pendente | cobrado | nao_cobrar
                'record_id'       => $rec?->id,
                'closed_at'       => $rec?->closed_at?->toISOString(),
            ];
        })
        // Mostra quem tem excedente (>0) OU já tem registro na competência.
        ->filter(fn ($r) => $r['excess_value'] > 0 || $r['record_id'] !== null)
        ->sortByDesc('excess_value')
        ->values();

        return response()->json([
            'data'        => $data,
            'total_geral' => round($data->sum('excess_value'), 2),
        ]);
    }

    /**
     * PATCH /fechamento-excedente/{project}/flag  — liga/desliga a cobrança do
     * excedente do contrato/projeto (default do cadastro).
     */
    public function toggleFlag(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate(['charge_excess_hours' => 'required|boolean']);
        $project->update(['charge_excess_hours' => $validated['charge_excess_hours']]);
        return response()->json(['ok' => true, 'charge_excess_hours' => (bool) $project->charge_excess_hours]);
    }

    /**
     * POST /fechamento-excedente/{project}/{yearMonth}
     * Registra/atualiza a apuração da competência com o status escolhido
     * (cobrado | nao_cobrar | pendente). Congela o snapshot do excedente e valor.
     */
    public function salvar(Request $request, int $project, string $yearMonth): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pendente,cobrado,nao_cobrar',
            'notes'  => 'nullable|string|max:1000',
        ]);

        $p = $this->baseQuery()->whereKey($project)->first();
        if (!$p) {
            return response()->json(['message' => 'Projeto não elegível a horas excedentes.'], 422);
        }

        $ap   = $p->excessHoursApuracao($yearMonth);
        $rate = (float) ($p->additional_hourly_rate ?? 0);

        // BH Fixo: o excedente a registrar é o incremental (atual − já cobrado).
        if ($ap['basis'] === 'fixed') {
            $jaCobrado = (float) ExcessHourCharge::where('status', ExcessHourCharge::STATUS_COBRADO)
                ->where('project_id', $p->id)->sum('excess_hours');
            $excess = max(0, round($ap['excess'] - $jaCobrado, 2));
        } else {
            $excess = $ap['excess'];
        }

        $rec = ExcessHourCharge::updateOrCreate(
            ['project_id' => $p->id, 'year_month' => $yearMonth],
            [
                'basis'                  => $ap['basis'],
                'contracted_hours'       => $ap['contracted'],
                'consumed_hours'         => $ap['consumed'],
                'excess_hours'           => $excess,
                'additional_hourly_rate' => $rate,
                'excess_value'           => round($excess * $rate, 2),
                'status'                 => $validated['status'],
                'notes'                  => $validated['notes'] ?? null,
                'closed_at'              => $validated['status'] === ExcessHourCharge::STATUS_COBRADO ? now() : null,
                'closed_by_id'           => $request->user()?->id,
            ]
        );

        return response()->json(['ok' => true, 'record' => $rec]);
    }
}
