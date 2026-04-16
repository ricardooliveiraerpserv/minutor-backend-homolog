<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ContractType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FechadoController extends Controller
{
    /**
     * Resolve o ContractType "Fechado" de forma robusta (case-insensitive).
     */
    private function fechadoContractType(): ?ContractType
    {
        return ContractType::whereRaw('LOWER(name) = ?', ['fechado'])->first()
            ?? ContractType::where('name', 'like', '%echado%')->first();
    }

    /**
     * Monta a query base de projetos Fechado.
     * Quando project_id é informado, busca diretamente (pai ou filho).
     * Quando não, busca todos os Fechado do cliente (incluindo filhos cujo pai não é Fechado).
     */
    private function buildQuery(Request $request, ?int $customerId, ContractType $fechadoType)
    {
        $query = Project::where('contract_type_id', $fechadoType->id);

        // Quando nenhum projeto específico é selecionado, exibe apenas raízes (independentes).
        // Quando um project_id é fornecido explicitamente, respeita a seleção do usuário.
        if (!$request->filled('project_id')) {
            $query->whereNull('parent_project_id');
        }

        if ($customerId) {
            $query->where('customer_id', $customerId);
        }

        if ($request->filled('executive_id')) {
            $executiveId = (int) $request->get('executive_id');
            $query->whereHas('customer', fn ($q) => $q->where('executive_id', $executiveId));
        }

        if ($request->filled('project_id')) {
            $query->where('id', (int) $request->get('project_id'));
        }

        return $query;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dashboard principal — cards de visão geral
    // ─────────────────────────────────────────────────────────────────────────

    public function fechado(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json(['success' => false, 'message' => 'Acesso negado.'], 403);
        }

        $customerId = $user->customer_id
            ?? ($user->isAdmin() && $request->has('customer_id') ? $request->get('customer_id') : null);

        $month = (int) ($request->get('month') ?: now()->month);
        $year  = (int) ($request->get('year')  ?: now()->year);

        $fechadoType = $this->fechadoContractType();
        if (!$fechadoType) {
            return response()->json(['success' => false, 'message' => 'Tipo de contrato "Fechado" não encontrado.'], 404);
        }

        $projects = $this->buildQuery($request, $customerId, $fechadoType)
            ->with('hourContributions')
            ->get();

        $consumedHours = $projects->sum(fn ($p) => (float) $p->getTotalAvailableHours());

        $monthConsumedHours = $projects
            ->filter(function ($p) use ($month, $year) {
                if (!$p->start_date) return false;
                $d = \Carbon\Carbon::parse($p->start_date);
                return $d->month === $month && $d->year === $year;
            })
            ->sum(fn ($p) => (float) $p->getTotalAvailableHours());

        // Histórico de aportes (novos) para os projetos encontrados
        $projectIds = $projects->pluck('id')->toArray();
        $contributionHistory = [];
        if (!empty($projectIds)) {
            $contributionHistory = \App\Models\HourContribution::whereIn('project_id', $projectIds)
                ->with(['project:id,name,code', 'contributedBy:id,name,email'])
                ->orderBy('contributed_at', 'desc')
                ->get()
                ->map(function ($c) {
                    return [
                        'id'                => 'contribution_' . $c->id,
                        'project'           => $c->project ? ['id' => $c->project->id, 'name' => $c->project->name, 'code' => $c->project->code] : null,
                        'contributed_hours' => $c->contributed_hours,
                        'hourly_rate'       => (float) $c->hourly_rate,
                        'total_value'       => $c->getTotalValue(),
                        'description'       => $c->description,
                        'changed_by'        => $c->contributedBy ? ['id' => $c->contributedBy->id, 'name' => $c->contributedBy->name] : null,
                        'created_at'        => $c->contributed_at->toIso8601String(),
                    ];
                })->toArray();
        }

        return response()->json([
            'success' => true,
            'message' => 'Dados do dashboard Fechado obtidos com sucesso',
            'data'    => [
                'consumed_hours'            => round($consumedHours, 1),
                'month_consumed_hours'      => round($monthConsumedHours, 1),
                'project_count'             => $projects->count(),
                'month_project_count'       => $projects->filter(function ($p) use ($month, $year) {
                    if (!$p->start_date) return false;
                    $d = \Carbon\Carbon::parse($p->start_date);
                    return $d->month === $month && $d->year === $year;
                })->count(),
                'contributed_hours_history' => $contributionHistory,
                'customer_id'               => $customerId,
                'month'                     => $month,
                'year'                      => $year,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lista de projetos — aba Projetos
    // ─────────────────────────────────────────────────────────────────────────

    public function fechadoProjects(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json(['success' => false, 'message' => 'Acesso negado.'], 403);
        }

        $customerId = $user->customer_id
            ?? ($user->isAdmin() && $request->has('customer_id') ? $request->get('customer_id') : null);

        $month = (int) ($request->get('month') ?: now()->month);
        $year  = (int) ($request->get('year')  ?: now()->year);

        $fechadoType = $this->fechadoContractType();
        if (!$fechadoType) {
            return response()->json(['success' => false, 'message' => 'Tipo "Fechado" não encontrado.'], 404);
        }

        $projects = $this->buildQuery($request, $customerId, $fechadoType)
            ->with('hourContributions')
            ->get();

        $data = $projects->map(function ($p) use ($month, $year) {
            $inMonth = false;
            if ($p->start_date) {
                $d = \Carbon\Carbon::parse($p->start_date);
                $inMonth = $d->month === $month && $d->year === $year;
            }
            $contributions = $p->relationLoaded('hourContributions')
                ? $p->hourContributions
                : $p->hourContributions()->get();
            $contributionHours = (float) $contributions->sum('contributed_hours');
            if ($contributionHours <= 0) {
                $contributionHours = (float) ($p->hour_contribution ?? 0);
            }
            return [
                'id'                 => $p->id,
                'name'               => $p->name,
                'code'               => $p->code,
                'status'             => $p->status,
                'base_hours'         => (float) ($p->sold_hours ?? 0),
                'contribution_hours' => $contributionHours,
                'sold_hours'         => (float) $p->getTotalAvailableHours(),
                'start_date'         => $p->start_date ? \Carbon\Carbon::parse($p->start_date)->format('Y-m-d') : null,
                'in_month'           => $inMonth,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Projetos Fechado obtidos com sucesso',
            'data'    => $data,
        ]);
    }
}
