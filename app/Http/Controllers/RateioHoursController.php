<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectRateioTarget;
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
            ->withCount('rateioTargets')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'customer_id'])
            ->map(fn ($p) => [
                'id'            => $p->id,
                'name'          => $p->name,
                'code'          => $p->code,
                'cliente'       => $p->customer?->name,
                'targets_count' => $p->rateio_targets_count,
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
}
