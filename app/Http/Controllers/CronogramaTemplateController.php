<?php

namespace App\Http\Controllers;

use App\Models\CronogramaTemplate;
use App\Models\Project;
use App\Services\CronogramaTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CronogramaTemplateController extends Controller
{
    public function __construct(private CronogramaTemplateService $service) {}

    /** Lista os modelos de cronograma (com contagem de etapas/atividades). */
    public function index(): JsonResponse
    {
        $items = CronogramaTemplate::active()
            ->with('creator:id,name')
            ->orderBy('name')
            ->get()
            ->map(function (CronogramaTemplate $t) {
                $stages = $t->payload['stages'] ?? [];
                $activities = collect($stages)->sum(fn ($s) => count($s['deliveries'] ?? []));
                return [
                    'id'              => $t->id,
                    'name'            => $t->name,
                    'description'     => $t->description,
                    'stages_count'    => count($stages),
                    'activities_count'=> $activities,
                    'created_by'      => $t->creator?->name,
                    'created_at'      => $t->created_at?->toDateString(),
                ];
            });

        return response()->json(['items' => $items]);
    }

    /** Salva o cronograma de um projeto como modelo reutilizável. */
    public function storeFromProject(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'              => 'required|string|max:150',
            'description'       => 'nullable|string|max:255',
            'source_project_id' => 'required|integer|exists:projects,id',
        ]);

        $source = Project::findOrFail($data['source_project_id']);
        $payload = $this->service->serialize($source);

        if (empty($payload['stages'])) {
            return response()->json(['message' => 'O projeto de origem não tem cronograma para salvar.'], 422);
        }

        $template = CronogramaTemplate::create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'payload'     => $payload,
            'created_by'  => $request->user()?->id,
            'active'      => true,
        ]);

        return response()->json($template, 201);
    }

    public function destroy(CronogramaTemplate $template): JsonResponse
    {
        $template->delete();
        return response()->json(null, 204);
    }

    /** Aplica um modelo salvo no projeto, ancorando na data de início. */
    public function apply(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'template_id' => 'required|integer|exists:cronograma_templates,id',
            'start_date'  => 'required|date',
        ]);

        $template = CronogramaTemplate::findOrFail($data['template_id']);
        $this->service->materialize($project, $template->payload, $data['start_date']);

        return response()->json(['message' => 'Modelo aplicado ao cronograma.']);
    }

    /** Copia o cronograma de outro projeto direto pro projeto atual. */
    public function copyFromProject(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'source_project_id' => 'required|integer|exists:projects,id',
            'start_date'        => 'required|date',
        ]);

        if ((int) $data['source_project_id'] === $project->id) {
            return response()->json(['message' => 'Escolha um projeto de origem diferente.'], 422);
        }

        $source = Project::findOrFail($data['source_project_id']);
        $payload = $this->service->serialize($source);

        if (empty($payload['stages'])) {
            return response()->json(['message' => 'O projeto de origem não tem cronograma para copiar.'], 422);
        }

        $this->service->materialize($project, $payload, $data['start_date']);

        return response()->json(['message' => 'Cronograma copiado para o projeto.']);
    }
}
