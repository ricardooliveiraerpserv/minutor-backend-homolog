<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ContractType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FechadoController extends Controller
{
    /**
     * Dashboard de projetos do tipo "Fechado".
     *
     * Regras:
     *  - Não há apontamentos — o consumo é baseado em sold_hours.
     *  - consumed_hours  = soma de sold_hours de todos os projetos Fechado do cliente.
     *  - month_consumed_hours = soma de sold_hours dos projetos cujo start_date cai no mês/ano filtrado.
     */
    public function fechado(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Usuário não autenticado'], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success'             => false,
                'message'             => 'Acesso negado.',
                'required_permission' => 'dashboards.view',
            ], 403);
        }

        // Determinar cliente
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtros de mês/ano
        $month = (int) ($request->get('month') ?: now()->month);
        $year  = (int) ($request->get('year')  ?: now()->year);

        // Tipo de contrato Fechado
        $fechadoType = ContractType::where('name', 'Fechado')->first();
        if (!$fechadoType) {
            return response()->json([
                'success' => false,
                'message' => 'Tipo de contrato "Fechado" não encontrado no sistema.',
            ], 404);
        }

        // Query base — apenas projetos raiz do tipo Fechado
        $query = Project::whereNull('parent_project_id')
            ->where('contract_type_id', $fechadoType->id);

        if ($customerId) {
            $query->where('customer_id', $customerId);
        }

        if ($user->isAdmin() && !$customerId && $request->filled('executive_id')) {
            $executiveId = (int) $request->get('executive_id');
            $query->whereHas('customer', fn ($q) => $q->where('executive_id', $executiveId));
        }

        if ($request->filled('project_id')) {
            $query->where('id', $request->get('project_id'));
        }

        $projects = $query->get();

        // consumed_hours = soma total de sold_hours
        $consumedHours = $projects->sum(fn ($p) => (float) ($p->sold_hours ?? 0));

        // month_consumed_hours = sold_hours de projetos cujo start_date cai no mês/ano filtrado
        $monthConsumedHours = $projects
            ->filter(function ($p) use ($month, $year) {
                if (!$p->start_date) return false;
                $d = \Carbon\Carbon::parse($p->start_date);
                return $d->month === $month && $d->year === $year;
            })
            ->sum(fn ($p) => (float) ($p->sold_hours ?? 0));

        return response()->json([
            'success' => true,
            'message' => 'Dados do dashboard Fechado obtidos com sucesso',
            'data'    => [
                'consumed_hours'       => round($consumedHours, 1),
                'month_consumed_hours' => round($monthConsumedHours, 1),
                'project_count'        => $projects->count(),
                'month_project_count'  => $projects->filter(function ($p) use ($month, $year) {
                    if (!$p->start_date) return false;
                    $d = \Carbon\Carbon::parse($p->start_date);
                    return $d->month === $month && $d->year === $year;
                })->count(),
                'customer_id'          => $customerId,
                'month'                => $month,
                'year'                 => $year,
            ],
        ]);
    }

    /**
     * Lista os projetos Fechado do cliente para exibição na aba de projetos.
     */
    public function fechadoProjects(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Usuário não autenticado'], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json(['success' => false, 'message' => 'Acesso negado.'], 403);
        }

        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        $month = (int) ($request->get('month') ?: now()->month);
        $year  = (int) ($request->get('year')  ?: now()->year);

        $fechadoType = ContractType::where('name', 'Fechado')->first();
        if (!$fechadoType) {
            return response()->json(['success' => false, 'message' => 'Tipo "Fechado" não encontrado.'], 404);
        }

        $query = Project::whereNull('parent_project_id')
            ->where('contract_type_id', $fechadoType->id);

        if ($customerId) {
            $query->where('customer_id', $customerId);
        }

        if ($user->isAdmin() && !$customerId && $request->filled('executive_id')) {
            $executiveId = (int) $request->get('executive_id');
            $query->whereHas('customer', fn ($q) => $q->where('executive_id', $executiveId));
        }

        $projects = $query->get();

        $data = $projects->map(function ($p) use ($month, $year) {
            $inMonth = false;
            if ($p->start_date) {
                $d = \Carbon\Carbon::parse($p->start_date);
                $inMonth = $d->month === $month && $d->year === $year;
            }
            return [
                'id'           => $p->id,
                'name'         => $p->name,
                'code'         => $p->code,
                'status'       => $p->status,
                'sold_hours'   => (float) ($p->sold_hours ?? 0),
                'start_date'   => $p->start_date ? \Carbon\Carbon::parse($p->start_date)->format('Y-m-d') : null,
                'in_month'     => $inMonth,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Projetos Fechado obtidos com sucesso',
            'data'    => $data,
        ]);
    }
}
