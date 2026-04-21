<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\FechamentoCliente;
use App\Models\Project;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FechamentoClienteController extends Controller
{
    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function period(string $yearMonth): array
    {
        $from = "{$yearMonth}-01";
        $to   = Carbon::parse($from)->endOfMonth()->toDateString();
        return [$from, $to];
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $yearMonth = $request->query('year_month');

        $customers = Customer::where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'company_name']);

        // Carrega fechamentos existentes para o mês consultado
        $fechamentos = $yearMonth
            ? FechamentoCliente::where('year_month', $yearMonth)
                ->with('closedByUser:id,name')
                ->get()
                ->keyBy('customer_id')
            : collect();

        $data = $customers->map(function ($customer) use ($fechamentos) {
            $f = $fechamentos->get($customer->id);
            return [
                'customer_id'    => $customer->id,
                'nome'           => $customer->company_name ?: $customer->name,
                'status'         => $f?->status ?? 'sem_registro',
                'total_servicos' => (float) ($f?->total_servicos ?? 0),
                'total_despesas' => (float) ($f?->total_despesas ?? 0),
                'total_geral'    => (float) ($f?->total_geral ?? 0),
                'closed_at'      => $f?->closed_at?->toISOString(),
                'closed_by_name' => $f?->closedByUser?->name,
            ];
        });

        return response()->json(['data' => $data]);
    }

    // ─── Contratos e Horas ────────────────────────────────────────────────────

    public function contratos(string $customerId, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoCliente::where('customer_id', $customerId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($fechamento?->isClosed() && $fechamento->snapshot_contratos) {
            return response()->json(['data' => $fechamento->snapshot_contratos, 'from_snapshot' => true]);
        }

        $data = $this->contratosData((int) $customerId, $yearMonth);
        return response()->json(['data' => $data, 'from_snapshot' => false]);
    }

    // ─── Despesas ────────────────────────────────────────────────────────────

    public function despesas(string $customerId, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoCliente::where('customer_id', $customerId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($fechamento?->isClosed() && $fechamento->snapshot_despesas) {
            return response()->json(['data' => $fechamento->snapshot_despesas, 'from_snapshot' => true]);
        }

        $data = $this->despesasData((int) $customerId, $yearMonth);
        return response()->json(['data' => $data, 'from_snapshot' => false]);
    }

    // ─── Fechar ──────────────────────────────────────────────────────────────

    public function fechar(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoCliente::firstOrNew([
            'customer_id' => $customerId,
            'year_month'  => $yearMonth,
        ]);

        if ($fechamento->exists && $fechamento->isClosed()) {
            return response()->json(['message' => 'Fechamento já está encerrado.'], 422);
        }

        $contratos = $this->contratosData((int) $customerId, $yearMonth);
        $despesas  = $this->despesasData((int) $customerId, $yearMonth);

        $totalServicos = round(collect($contratos)->sum('total_receita'), 2);
        $totalDespesas = round(collect($despesas)->sum('valor'), 2);

        $fechamento->fill([
            'status'             => 'closed',
            'snapshot_contratos' => $contratos,
            'snapshot_despesas'  => $despesas,
            'total_servicos'     => $totalServicos,
            'total_despesas'     => $totalDespesas,
            'total_geral'        => round($totalServicos + $totalDespesas, 2),
            'closed_at'          => now(),
            'closed_by'          => $request->user()->id,
            'notes'              => $request->input('notes'),
        ])->save();

        return response()->json(['message' => "Fechamento do cliente para {$yearMonth} encerrado.", 'fechamento' => $fechamento]);
    }

    // ─── Reabrir ─────────────────────────────────────────────────────────────

    public function reabrir(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Sem permissão para reabrir fechamentos.'], 403);
        }

        $fechamento = FechamentoCliente::where('customer_id', $customerId)
            ->where('year_month', $yearMonth)
            ->firstOrFail();

        $fechamento->update([
            'status'             => 'open',
            'closed_at'          => null,
            'closed_by'          => null,
            'snapshot_contratos' => null,
            'snapshot_despesas'  => null,
        ]);

        return response()->json(['message' => "Fechamento do cliente reaberto para {$yearMonth}."]);
    }

    // ─── Helpers privados ────────────────────────────────────────────────────

    private function contratosData(int $customerId, string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        // Projetos do cliente com apontamentos aprovados no período
        $projectIds = Timesheet::whereBetween('date', [$from, $to])
            ->where('status', Timesheet::STATUS_APPROVED)
            ->whereNull('deleted_at')
            ->whereHas('project', fn ($q) => $q->where('customer_id', $customerId))
            ->distinct()
            ->pluck('project_id');

        if ($projectIds->isEmpty()) {
            return [];
        }

        $projects = Project::with(['contractType:id,name,code'])
            ->whereIn('id', $projectIds)
            ->get();

        $hoursByProject = Timesheet::whereBetween('date', [$from, $to])
            ->where('status', Timesheet::STATUS_APPROVED)
            ->whereNull('deleted_at')
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(effort_minutes) as total_minutes')
            ->groupBy('project_id')
            ->pluck('total_minutes', 'project_id');

        // Horas consumidas totais (acumulado) por projeto para banco de horas
        $totalConsumedByProject = Timesheet::where('status', Timesheet::STATUS_APPROVED)
            ->whereNull('deleted_at')
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(effort_minutes) as total_minutes')
            ->groupBy('project_id')
            ->pluck('total_minutes', 'project_id');

        $rows = [];
        foreach ($projects as $project) {
            $totalHours   = round((int) ($hoursByProject[$project->id] ?? 0) / 60, 2);
            $contractCode = strtolower($project->contractType->code ?? '');
            $hourlyRate   = (float) ($project->hourly_rate ?? 0);
            $projectValue = (float) ($project->project_value ?? 0);
            $soldHours    = (float) ($project->sold_hours ?? 0);
            $consumedAll  = round((int) ($totalConsumedByProject[$project->id] ?? 0) / 60, 2);

            if (str_contains($contractCode, 'on_demand') || str_contains($contractCode, 'ondemand')) {
                $totalReceita    = round($totalHours * $hourlyRate, 2);
                $tipoFaturamento = 'on_demand';
                $valorBase       = $hourlyRate;
            } elseif (str_contains($contractCode, 'banco_horas') || str_contains($contractCode, 'bank_hours')) {
                $totalReceita    = round($soldHours * $hourlyRate, 2);
                $tipoFaturamento = 'banco_horas';
                $valorBase       = $hourlyRate;
            } elseif (str_contains($contractCode, 'fechado')) {
                $totalReceita    = $projectValue;
                $tipoFaturamento = 'fechado';
                $valorBase       = $projectValue;
            } else {
                $totalReceita    = round($totalHours * $hourlyRate, 2);
                $tipoFaturamento = $contractCode ?: 'outros';
                $valorBase       = $hourlyRate;
            }

            $rows[] = [
                'projeto_id'          => $project->id,
                'projeto_nome'        => $project->name,
                'projeto_codigo'      => $project->code ?? '—',
                'tipo_contrato'       => $project->contractType->name ?? '—',
                'tipo_faturamento'    => $tipoFaturamento,
                'horas_aprovadas'     => $totalHours,
                'horas_contratadas'   => $soldHours,
                'horas_consumidas'    => $consumedAll,
                'valor_base'          => $valorBase,
                'total_receita'       => $totalReceita,
            ];
        }

        return $rows;
    }

    private function despesasData(int $customerId, string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        return Expense::with([
            'user:id,name',
            'project:id,name,code',
            'category:id,name',
        ])
            ->where('charge_client', true)
            ->where('status', 'approved')
            ->whereBetween('expense_date', [$from, $to])
            ->whereHas('project', fn ($q) => $q->where('customer_id', $customerId))
            ->get()
            ->map(fn ($e) => [
                'id'          => $e->id,
                'data'        => $e->expense_date->format('Y-m-d'),
                'descricao'   => $e->description,
                'categoria'   => $e->category->name ?? '—',
                'colaborador' => $e->user->name ?? '—',
                'projeto'     => $e->project->name ?? '—',
                'valor'       => (float) $e->amount,
            ])
            ->toArray();
    }
}
