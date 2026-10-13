<?php

namespace App\Http\Controllers;

use App\Models\OnDemandBillingRelease;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tela gerencial de CONTRATOS ON DEMAND + liberação de faturamento por competência.
 *
 * - index(): uma linha por contrato On Demand (projeto PAI, code 'on_demand', não
 *   investimento comercial), com horas A APROVAR e APROVADAS no mês e o status de
 *   liberação. Escopo de visibilidade = MESMA regra de Demandas/Projetos (coordenador
 *   efetivo via override Kanban OU M2M; sustentação enxerga tudo de sustentação).
 * - toggle(): o COORDENADOR RESPONSÁVEL do contrato (ou admin) marca "liberado para
 *   faturar". A existência da linha (project_id, year_month) = liberado.
 *
 * O gate que bloqueia o ENVIO do fechamento ao cliente vive em
 * FechamentoClienteController@enviarEmail (consulta esta mesma liberação).
 */
class OnDemandBillingReleaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $yearMonth = (string) $request->query('year_month', '');
        if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
            $yearMonth = now()->format('Y-m');
        }
        $monthStart = $yearMonth . '-01';
        $monthEnd   = Carbon::parse($monthStart)->endOfMonth()->toDateString();

        // Base: contratos On Demand REAIS = projeto PAI, contractType on_demand,
        // exclui buckets de investimento comercial interno. (mesma regra do FechamentoCliente)
        $query = Project::query()
            ->whereNull('parent_project_id')
            ->whereHas('contractType', fn ($q) => $q->where('code', 'on_demand'))
            ->where(function ($q) {
                $q->where('is_investimento_comercial', false)
                  ->orWhereNull('is_investimento_comercial');
            })
            ->with([
                'customer:id,name,company_name,executive_id',
                'customer.executive:id,name',
                'coordinators:id,name',
                'kanbanOverrideCoordinator:id,name',
                'serviceType:id,code',
            ]);

        // Escopo de coordenador — idêntico a Demandas/Projetos (ProjectController@index).
        // Admin/administrativo veem tudo; coordenador vê onde é coordenador efetivo
        // (pai ou filhos) e, se for de SUSTENTAÇÃO, tudo com serviceType sustentação.
        if ($user && $user->isCoordenador() && !$user->isAdmin() && !$user->isAdministrativo()) {
            $isSustentacao = $user->coordinator_type === 'sustentacao';
            $query->where(function ($q) use ($user, $isSustentacao) {
                $effClosure = function ($eff) use ($user) {
                    $eff->where('kanban_coordinator_override_id', $user->id)
                        ->orWhere(function ($m2m) use ($user) {
                            $m2m->whereNull('kanban_coordinator_override_id')
                                ->whereHas('coordinators', fn ($sq) => $sq->where('users.id', $user->id));
                        });
                };
                $q->where($effClosure)
                  ->orWhereHas('childProjects', $effClosure);
                if ($isSustentacao) {
                    $q->orWhereHas('serviceType', fn ($sq) => $sq->where('code', 'sustentacao'))
                      ->orWhere(fn ($sq) => $sq->where('is_investimento_comercial', true)
                                               ->where('categoria_interna', 'Suporte'));
                }
            });
        }

        $projects = $query->orderBy('code')->get();
        $releases = OnDemandBillingRelease::mapFor($yearMonth);

        // Coordenadores de SUSTENTAÇÃO (regra de Demandas/Projetos): governam todos os
        // contratos de sustentação. Usado como "coordenador responsável" dos contratos
        // On Demand de sustentação que não têm coordenador individual (override/M2M).
        $sustCoordNames = User::where('enabled', true)
            ->where('coordinator_type', 'sustentacao')
            ->where(function ($q) { $q->where('type', 'coordenador')->orWhere('is_coordinator', true); })
            ->orderBy('name')->pluck('name');
        $userIsSustCoord = ($user->coordinator_type === 'sustentacao')
            && ($user->type === 'coordenador' || $user->is_coordinator);

        // A liberação vale DESTE MÊS PARA FRENTE. Competências anteriores já foram
        // faturadas → tratadas como JÁ LIBERADAS (sem exigir ação / sem bloquear envio).
        $isPast = $yearMonth < now()->format('Y-m');

        $rows = $projects->map(function (Project $p) use ($monthStart, $monthEnd, $releases, $user, $sustCoordNames, $userIsSustCoord, $isPast) {
            // Horas do CONTRATO = projeto pai + filhos.
            $ids = collect([$p->id])
                ->merge(Project::where('parent_project_id', $p->id)->pluck('id'))
                ->all();

            $base = Timesheet::whereIn('project_id', $ids)
                ->whereBetween('date', [$monthStart, $monthEnd]);

            $aprovarMin = (float) (clone $base)
                ->where('status', Timesheet::STATUS_PENDING)
                ->sum('effort_minutes');
            $ajusteMin = (float) (clone $base)
                ->where('status', Timesheet::STATUS_ADJUSTMENT_REQUESTED)
                ->sum('effort_minutes');
            $aprovadasMin = (float) (clone $base)
                ->where('status', Timesheet::STATUS_APPROVED)
                ->sum('effort_minutes');

            $rel = $releases[$p->id] ?? null;

            // Coordenador EFETIVO para exibição: override do Kanban vence; senão M2M;
            // senão, se for contrato de SUSTENTAÇÃO, os coordenadores de sustentação.
            $isSust = ($p->serviceType?->code === 'sustentacao');
            $coordNames = collect();
            if ($p->kanbanOverrideCoordinator) {
                $coordNames = collect([$p->kanbanOverrideCoordinator->name]);
            } elseif ($p->coordinators && $p->coordinators->isNotEmpty()) {
                $coordNames = $p->coordinators->pluck('name');
            } elseif ($isSust) {
                $coordNames = $sustCoordNames ?? collect();
            }

            return [
                'project_id'      => $p->id,
                'contract_code'   => $p->code,
                'contract_name'   => $p->name,
                'customer'        => $p->customer?->name ?? $p->customer?->company_name,
                'customer_id'     => $p->customer?->id,
                'executivo'       => $p->customer?->executive?->name,
                'coordinators'    => $coordNames->filter()->values(),
                'horas_a_aprovar' => round($aprovarMin / 60, 2),
                'horas_ajuste'    => round($ajusteMin / 60, 2),
                'horas_aprovadas' => round($aprovadasMin / 60, 2),
                'horas_total'     => round(($aprovarMin + $ajusteMin + $aprovadasMin) / 60, 2),
                // % aprovado: só chega a 100% quando pendentes E ajustes = 0.
                'pct_aprovado'    => ($aprovarMin + $ajusteMin + $aprovadasMin) > 0
                                     ? (int) round($aprovadasMin / ($aprovarMin + $ajusteMin + $aprovadasMin) * 100)
                                     : 0,
                'released'        => $isPast ? true : (bool) $rel,
                'released_by'     => $isPast ? null : $rel?->releasedBy?->name,
                'released_at'     => $isPast ? null : $rel?->released_at,
                'auto_released'   => $isPast, // mês anterior = já faturado (liberado automático)
                'can_release'     => !$isPast && ($user->isAdmin() || $user->isAdministrativo()
                                     || self::isEffectiveCoordinator($p, (int) $user->id)
                                     || ($isSust && $userIsSustCoord)),
            ];
        })
        // Só contratos COM movimento no mês (horas > 0); sem movimento não é listado.
        ->filter(fn ($r) => $r['horas_total'] > 0)
        ->values();

        return response()->json(['data' => $rows, 'year_month' => $yearMonth]);
    }

    public function toggle(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'year_month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'released'   => 'required|boolean',
            'reason'     => 'nullable|string|max:500',
        ]);

        $project = Project::with('coordinators:id', 'serviceType:id,code')->findOrFail($data['project_id']);

        $userIsSustCoord = ($user->coordinator_type === 'sustentacao')
            && ($user->type === 'coordenador' || $user->is_coordinator);
        $canRelease = $user->isAdmin() || $user->isAdministrativo()
            || self::isEffectiveCoordinator($project, (int) $user->id)
            || (($project->serviceType?->code === 'sustentacao') && $userIsSustCoord);
        if (!$canRelease) {
            return response()->json([
                'message' => 'Apenas o coordenador responsável deste contrato (ou admin) pode liberar o faturamento.',
            ], 403);
        }

        if ($data['released']) {
            OnDemandBillingRelease::updateOrCreate(
                ['project_id' => $data['project_id'], 'year_month' => $data['year_month']],
                ['released_at' => now(), 'released_by' => $user->id, 'reason' => $data['reason'] ?? null],
            );
        } else {
            OnDemandBillingRelease::where('project_id', $data['project_id'])
                ->where('year_month', $data['year_month'])
                ->delete();
        }

        return response()->json(['success' => true, 'released' => (bool) $data['released']]);
    }

    /** Coordenador EFETIVO: override do Kanban vence; senão, M2M coordinators. */
    public static function isEffectiveCoordinator(Project $project, int $userId): bool
    {
        if ($project->kanban_coordinator_override_id) {
            return (int) $project->kanban_coordinator_override_id === $userId;
        }
        if (!$project->relationLoaded('coordinators')) {
            $project->load('coordinators:id');
        }
        return $project->coordinators->contains('id', $userId);
    }
}
