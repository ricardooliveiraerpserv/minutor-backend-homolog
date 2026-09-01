<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectRateioPlan;
use App\Models\ProjectRateioTarget;
use App\Models\Timesheet;
use App\Services\RateioHoursService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuração do RATEIO DE HORAS: projetos-servidor (is_rateio) e seus destinos + %.
 * O fan-out em si acontece no TimesheetController via RateioHoursService.
 */
class RateioHoursController extends Controller
{
    /** Lista os projetos de rateio (servidores) com contagem de destinos. */
    public function index(): JsonResponse
    {
        $projects = Project::where('is_rateio', true)
            ->with('customer:id,name', 'consultants:id,name', 'coordinators:id,name')
            ->withCount('rateioTargets', 'rateioPlans')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'customer_id'])
            ->map(fn ($p) => [
                'id'            => $p->id,
                'name'          => $p->name,
                'code'          => $p->code,
                'cliente'       => $p->customer?->name,
                'targets_count' => $p->rateio_targets_count,
                'plans_count'   => $p->rateio_plans_count,
                // Equipe alocada (para apontar + aprovar). coordinator = 1 (M2M, max 1).
                'consultants'   => $p->consultants->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
                'coordinator'   => $p->coordinators->first() ? ['id' => $p->coordinators->first()->id, 'name' => $p->coordinators->first()->name] : null,
            ]);

        return response()->json(['data' => $projects]);
    }

    /** Destinos + % de um projeto de rateio (+ metadados p/ a tela). */
    public function targets(Project $project): JsonResponse
    {
        if (!$project->is_rateio) {
            return response()->json(['message' => 'Projeto não é de rateio.'], 422);
        }
        $rows = $project->rateioTargets()->with('targetProject:id,name,code,customer_id', 'targetProject.customer:id,name')->get()
            ->map(fn ($t) => [
                'id'                => $t->id,
                'target_project_id' => $t->target_project_id,
                'projeto'           => $t->targetProject?->name,
                'projeto_codigo'    => $t->targetProject?->code,
                'cliente'           => $t->targetProject?->customer?->name,
                'percentual'        => (float) $t->percentual,
            ]);

        return response()->json([
            'project' => ['id' => $project->id, 'name' => $project->name, 'code' => $project->code],
            'targets' => $rows,
        ]);
    }

    /** Salva os destinos + % (soma deve fechar 100%). Destino pode ser de QUALQUER cliente. */
    public function saveTargets(Project $project, Request $request): JsonResponse
    {
        if (!$project->is_rateio) {
            return response()->json(['message' => 'Projeto não é de rateio.'], 422);
        }
        $data = $request->validate([
            'targets'                     => 'present|array',
            'targets.*.target_project_id' => 'required|integer|exists:projects,id',
            'targets.*.percentual'        => 'required|numeric|min:0|max:100',
        ]);

        $targets = collect($data['targets']);
        // Não pode ratear para o próprio projeto de rateio, nem duplicar destino.
        if ($targets->pluck('target_project_id')->contains($project->id)) {
            return response()->json(['message' => 'O projeto de rateio não pode ser destino de si mesmo.'], 422);
        }
        if ($targets->pluck('target_project_id')->duplicates()->isNotEmpty()) {
            return response()->json(['message' => 'Há projeto de destino repetido.'], 422);
        }
        if ($targets->isNotEmpty()) {
            $sum = round($targets->sum('percentual'), 2);
            if (abs($sum - 100) > 0.01) {
                return response()->json(['message' => "A soma dos percentuais deve ser 100% (atual: {$sum}%)."], 422);
            }
        }

        $project->rateioTargets()->delete();
        foreach ($targets->values() as $i => $t) {
            ProjectRateioTarget::create([
                'rateio_project_id' => $project->id,
                'target_project_id' => (int) $t['target_project_id'],
                'percentual'        => $t['percentual'],
                'position'          => $i,
            ]);
        }

        return $this->targets($project->fresh());
    }

    /**
     * Aloca a equipe do projeto-servidor de rateio: consultores (que poderão APONTAR
     * horas nele) + 1 coordenador (que APROVA). Endpoint leve/isolado — só mexe nos
     * pivôs project_consultants/project_coordinators, sem tocar nos demais campos do
     * projeto (o servidor de rateio costuma nascer com campos zerados).
     */
    public function saveTeam(Project $project, Request $request): JsonResponse
    {
        if (!$project->is_rateio) {
            return response()->json(['message' => 'Projeto não é de rateio.'], 422);
        }
        $data = $request->validate([
            'consultant_ids'   => 'nullable|array',
            'consultant_ids.*' => 'integer|exists:users,id',
            'coordinator_id'   => 'nullable|integer|exists:users,id',
        ]);

        $project->consultants()->sync($data['consultant_ids'] ?? []);
        $project->coordinators()->sync(!empty($data['coordinator_id']) ? [$data['coordinator_id']] : []);

        return $this->index();
    }

    /** Períodos (vigências) + destinos de um projeto-servidor de rateio. */
    public function plans(Project $project): JsonResponse
    {
        if (!$project->is_rateio) {
            return response()->json(['message' => 'Projeto não é de rateio.'], 422);
        }
        $rows = $project->rateioPlans()
            ->with('targets.targetProject:id,name,code,customer_id', 'targets.targetProject.customer:id,name')
            ->get()
            ->map(fn ($plan) => [
                'id'          => $plan->id,
                'data_inicio' => optional($plan->data_inicio)->format('Y-m-d'),
                'data_fim'    => optional($plan->data_fim)->format('Y-m-d'),
                'targets'     => $plan->targets->map(fn ($t) => [
                    'target_project_id' => $t->target_project_id,
                    'projeto'           => $t->targetProject?->name,
                    'projeto_codigo'    => $t->targetProject?->code,
                    'cliente'           => $t->targetProject?->customer?->name,
                    'percentual'        => (float) $t->percentual,
                ])->values(),
            ]);

        return response()->json([
            'project' => ['id' => $project->id, 'name' => $project->name, 'code' => $project->code],
            'plans'   => $rows,
        ]);
    }

    /**
     * Substitui os períodos + destinos. Períodos são EXCLUSIVOS no tempo (sem sobreposição).
     * Pesos por período são normalizados p/ 100% na distribuição (aceita qualquer peso > 0).
     */
    public function savePlans(Project $project, Request $request): JsonResponse
    {
        if (!$project->is_rateio) {
            return response()->json(['message' => 'Projeto não é de rateio.'], 422);
        }
        $data = $request->validate([
            'plans'                            => 'present|array',
            'plans.*.data_inicio'              => 'nullable|date',
            'plans.*.data_fim'                 => 'nullable|date',
            'plans.*.targets'                  => 'present|array',
            'plans.*.targets.*.target_project_id' => 'required|integer|exists:projects,id',
            'plans.*.targets.*.percentual'     => 'required|numeric|min:0|max:100',
        ]);

        $plans = collect($data['plans']);

        // Validações por período: início<=fim, destino≠servidor, sem destino repetido, soma>0.
        foreach ($plans as $idx => $p) {
            $ini = $p['data_inicio'] ?? null;
            $fim = $p['data_fim'] ?? null;
            if ($ini && $fim && $ini > $fim) {
                return response()->json(['message' => 'Um período tem a data de início depois da data de fim.'], 422);
            }
            $tids = collect($p['targets'] ?? [])->pluck('target_project_id');
            if ($tids->contains($project->id)) {
                return response()->json(['message' => 'O projeto de rateio não pode ser destino de si mesmo.'], 422);
            }
            if ($tids->duplicates()->isNotEmpty()) {
                return response()->json(['message' => 'Há destino repetido no mesmo período.'], 422);
            }
            $soma = collect($p['targets'] ?? [])->sum('percentual');
            if (!empty($p['targets']) && $soma <= 0) {
                return response()->json(['message' => 'A soma dos pesos de um período deve ser maior que zero.'], 422);
            }
        }

        // Períodos exclusivos: nenhum par pode se sobrepor no tempo (null = ±infinito).
        $arr = $plans->values()->all();
        for ($i = 0; $i < count($arr); $i++) {
            for ($j = $i + 1; $j < count($arr); $j++) {
                $aS = $arr[$i]['data_inicio'] ?? '0000-01-01'; $aE = $arr[$i]['data_fim'] ?? '9999-12-31';
                $bS = $arr[$j]['data_inicio'] ?? '0000-01-01'; $bE = $arr[$j]['data_fim'] ?? '9999-12-31';
                if ($aS <= $bE && $bS <= $aE) {
                    return response()->json(['message' => 'Há períodos com datas sobrepostas. Os períodos devem ser exclusivos.'], 422);
                }
            }
        }

        \DB::transaction(function () use ($project, $plans) {
            $project->rateioPlans()->delete(); // cascade apaga os targets do período
            foreach ($plans->values() as $pi => $p) {
                $plan = ProjectRateioPlan::create([
                    'rateio_project_id' => $project->id,
                    'data_inicio'       => $p['data_inicio'] ?? null,
                    'data_fim'          => $p['data_fim'] ?? null,
                    'position'          => $pi,
                ]);
                foreach (collect($p['targets'] ?? [])->values() as $ti => $t) {
                    ProjectRateioTarget::create([
                        'rateio_project_id' => $project->id,
                        'plan_id'           => $plan->id,
                        'target_project_id' => (int) $t['target_project_id'],
                        'percentual'        => $t['percentual'],
                        'position'          => $ti,
                    ]);
                }
            }
        });

        // RETROATIVO: re-distribui os apontamentos já lançados no servidor conforme os novos
        // períodos — PRESERVANDO os que foram ajustados manualmente (rateio_overridden=true).
        // Sem período na data => sync limpa os filhos (não distribui).
        $svc = app(RateioHoursService::class);
        Timesheet::where('project_id', $project->id)
            ->whereNull('rateio_source_timesheet_id')
            ->where('rateio_overridden', false)
            ->with('project')
            ->chunkById(200, function ($parents) use ($svc) {
                foreach ($parents as $parent) {
                    $svc->sync($parent, null);
                }
            });

        return $this->plans($project->fresh());
    }

    /** Apontamentos feitos NO servidor de rateio + como cada um foi dividido. */
    public function timesheets(Project $project): JsonResponse
    {
        if (!$project->is_rateio) {
            return response()->json(['message' => 'Projeto não é de rateio.'], 422);
        }
        $parents = Timesheet::where('project_id', $project->id)
            ->whereNull('rateio_source_timesheet_id')
            ->with('user:id,name')
            ->orderByDesc('date')->orderByDesc('id')
            ->limit(300)->get();

        $ids = $parents->pluck('id');
        $childrenByParent = Timesheet::whereIn('rateio_source_timesheet_id', $ids)
            ->with('project:id,name,code')
            ->get()->groupBy('rateio_source_timesheet_id');

        $rows = $parents->map(function ($ts) use ($childrenByParent) {
            $kids = $childrenByParent->get($ts->id, collect());
            return [
                'id'             => $ts->id,
                'date'           => $ts->date instanceof \Carbon\Carbon ? $ts->date->format('Y-m-d') : (string) $ts->date,
                'consultor'      => $ts->user?->name,
                'effort_minutes' => (int) $ts->effort_minutes,
                'status'         => $ts->status,
                'overridden'     => (bool) $ts->rateio_overridden,
                'splits'         => $kids->map(fn ($c) => [
                    'target_project_id' => $c->project_id,
                    'projeto'           => $c->project?->name,
                    'projeto_codigo'    => $c->project?->code,
                    'minutes'           => (int) $c->effort_minutes,
                ])->values(),
            ];
        });

        return response()->json(['data' => $rows]);
    }

    /**
     * Ajuste MANUAL da divisão de um apontamento do servidor (override do período): recebe
     * minutos por destino e re-sincroniza os filhos. A soma deve fechar o total do pai.
     */
    public function overrideTimesheet(Project $project, Timesheet $timesheet, Request $request): JsonResponse
    {
        if (!$project->is_rateio) {
            return response()->json(['message' => 'Projeto não é de rateio.'], 422);
        }
        if ($timesheet->project_id !== $project->id || $timesheet->rateio_source_timesheet_id !== null) {
            return response()->json(['message' => 'Apontamento inválido para este servidor de rateio.'], 422);
        }

        // Voltar ao automático: limpa o flag manual e re-distribui pelo período da data.
        if ($request->boolean('auto')) {
            $timesheet->rateio_overridden = false;
            $timesheet->save();
            app(RateioHoursService::class)->sync($timesheet, null);
            return $this->timesheets($project);
        }

        $data = $request->validate([
            'distribution'                     => 'present|array',
            'distribution.*.target_project_id' => 'required|integer|exists:projects,id',
            'distribution.*.minutes'           => 'required|numeric|min:0',
        ]);

        $dist = collect($data['distribution'])->filter(fn ($d) => (int) $d['minutes'] > 0)->values();
        $soma = (int) $dist->sum(fn ($d) => (int) round($d['minutes']));
        $total = (int) $timesheet->effort_minutes;
        if ($soma !== $total) {
            return response()->json(['message' => "A soma da divisão ({$soma} min) deve fechar o total do apontamento ({$total} min)."], 422);
        }

        app(RateioHoursService::class)->sync($timesheet, $dist->all());
        // Marca como ajustado manualmente → a re-distribuição retroativa (savePlans) o preserva.
        $timesheet->rateio_overridden = true;
        $timesheet->save();

        return $this->timesheets($project);
    }

    /**
     * Exclui o apontamento-ORIGEM do servidor e ESTORNA os rateios (filhos). SOFT-DELETE
     * (deleted_at) em ambos — nunca físico (auditoria financeira). Ação deliberada do
     * usuário (confirmada no front).
     */
    public function destroyTimesheet(Project $project, Timesheet $timesheet): JsonResponse
    {
        if (!$project->is_rateio) {
            return response()->json(['message' => 'Projeto não é de rateio.'], 422);
        }
        if ($timesheet->project_id !== $project->id || $timesheet->rateio_source_timesheet_id !== null) {
            return response()->json(['message' => 'Apontamento inválido para este servidor de rateio.'], 422);
        }

        // Estorna os rateios (filhos) e exclui o original — soft-delete (o model usa SoftDeletes).
        Timesheet::where('rateio_source_timesheet_id', $timesheet->id)->delete();
        $timesheet->delete();

        return $this->timesheets($project);
    }
}
