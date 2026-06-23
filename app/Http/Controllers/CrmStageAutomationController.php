<?php

namespace App\Http\Controllers;

use App\Models\CrmPipelineEvent;
use App\Models\CrmPipelineStage;
use App\Models\CrmStageAutomation;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CRM — gestão das automações de etapa (Fase 3) + governança/auditoria (Fase 5). */
class CrmStageAutomationController extends Controller
{
    /** Restrito a admin/administrativo (crm.pipeline.manage). */
    private function authorizeConfig(): void
    {
        $perms = auth()->check() ? PermissionService::for(auth()->user()) : [];
        abort_unless(in_array('*', $perms, true) || in_array('crm.pipeline.manage', $perms, true), 403, 'Sem permissão para configurar automações.');
    }

    public function index(CrmPipelineStage $stage): JsonResponse
    {
        $this->authorizeConfig();
        return response()->json(['data' => $stage->automations()->orderBy('ordem')->get(),
            'tipos' => CrmStageAutomation::TIPOS]);
    }

    public function store(Request $request, CrmPipelineStage $stage): JsonResponse
    {
        $this->authorizeConfig();
        $v = $this->rules($request, true);
        $v['stage_id'] = $stage->id;
        $v['evento'] = 'ao_entrar';
        $v['ordem'] = (int) CrmStageAutomation::where('stage_id', $stage->id)->max('ordem') + 1;
        $a = CrmStageAutomation::create($v);
        CrmPipelineEvent::log('automacao_criada', $stage->pipeline_id, $stage->id, "Automação \"{$a->tipo}\" criada", null, ['tipo' => $a->tipo, 'config' => $a->config]);
        return response()->json(['data' => $a], 201);
    }

    public function update(Request $request, CrmStageAutomation $automation): JsonResponse
    {
        $this->authorizeConfig();
        $antes = $automation->only(['tipo', 'config', 'ativa']);
        $automation->update($this->rules($request, false));
        CrmPipelineEvent::log('automacao_alterada', $automation->stage->pipeline_id, $automation->stage_id, "Automação \"{$automation->tipo}\"", $antes, $automation->only(['tipo', 'config', 'ativa']));
        return response()->json(['data' => $automation->fresh()]);
    }

    public function destroy(CrmStageAutomation $automation): JsonResponse
    {
        $this->authorizeConfig();
        CrmPipelineEvent::log('automacao_removida', $automation->stage->pipeline_id, $automation->stage_id, "Automação \"{$automation->tipo}\" removida");
        $automation->delete();
        return response()->json(['ok' => true]);
    }

    private function rules(Request $request, bool $creating): array
    {
        return $request->validate([
            'tipo'   => ($creating ? 'required' : 'sometimes') . '|in:' . implode(',', CrmStageAutomation::TIPOS),
            'config' => 'nullable|array',
            'ativa'  => 'boolean',
        ]);
    }
}
