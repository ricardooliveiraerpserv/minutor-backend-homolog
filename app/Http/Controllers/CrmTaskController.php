<?php

namespace App\Http\Controllers;

use App\Models\CrmOpportunity;
use App\Models\CrmOpportunityEvent;
use App\Models\CrmTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CRM — tarefas comerciais / Próxima Ação de uma oportunidade. */
class CrmTaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['opportunity_id' => 'nullable|exists:crm_opportunities,id', 'customer_id' => 'nullable|exists:customers,id']);
        abort_unless($request->filled('opportunity_id') || $request->filled('customer_id'), 422, 'Informe opportunity_id ou customer_id.');
        $tasks = CrmTask::with('responsavel:id,name', 'opportunity:id,title')
            ->when($request->filled('opportunity_id'), fn ($q) => $q->where('opportunity_id', $request->opportunity_id))
            ->when($request->filled('customer_id') && !$request->filled('opportunity_id'), fn ($q) => $q->where('customer_id', $request->customer_id))
            ->orderByRaw('concluida_at IS NULL DESC')->orderBy('data')
            ->get();
        return response()->json(['data' => $tasks]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'customer_id'    => 'nullable|exists:customers,id',
            'opportunity_id' => 'nullable|exists:crm_opportunities,id',
            'contract_id'    => 'nullable|exists:contracts,id',
            'project_id'     => 'nullable|exists:projects,id',
            'tipo'           => 'required|string|max:60', // tipo de contato vem do cadastro crm_contact_types
            'categoria'      => 'nullable|in:' . implode(',', CrmTask::CATEGORIAS),
            'titulo'         => 'nullable|string|max:180',
            'data'           => 'nullable|date',
            'responsavel_id' => 'nullable|exists:users,id',
            'prioridade'     => 'nullable|in:baixa,media,alta',
            'notas'          => 'nullable|string',
        ]);
        // Fase 7: todo follow-up pertence a uma EMPRESA (deriva da oportunidade se não vier).
        if (empty($v['customer_id']) && !empty($v['opportunity_id'])) {
            $v['customer_id'] = CrmOpportunity::find($v['opportunity_id'])?->customer_id;
        }
        abort_unless(!empty($v['customer_id']), 422, 'Follow-up exige uma empresa (customer_id).');
        $v['created_by_id'] = auth()->id();
        $task = CrmTask::create($v);
        if (!empty($v['opportunity_id'])) $this->recompute((int) $v['opportunity_id']);
        return response()->json(['data' => $task->load('responsavel:id,name')], 201);
    }

    /** Conclui (ou reabre) uma tarefa. */
    public function complete(Request $request, CrmTask $crmTask): JsonResponse
    {
        $done = $request->boolean('done', true);
        $crmTask->update(['concluida_at' => $done ? now() : null]);
        if ($done && $crmTask->opportunity_id) {
            CrmOpportunityEvent::log($crmTask->opportunity_id, 'task_done', ['to_value' => $crmTask->titulo ?: $crmTask->tipo]);
        }
        if ($crmTask->opportunity_id) $this->recompute($crmTask->opportunity_id);
        return response()->json(['data' => $crmTask->fresh()->load('responsavel:id,name')]);
    }

    public function destroy(CrmTask $crmTask): JsonResponse
    {
        $oppId = $crmTask->opportunity_id;
        $crmTask->delete();
        if ($oppId) $this->recompute($oppId);
        return response()->json(null, 204);
    }

    /** Atualiza na oportunidade: próxima ação (próxima task aberta) e última interação (max concluída). */
    private function recompute(int $opportunityId): void
    {
        $o = CrmOpportunity::find($opportunityId);
        if (!$o) return;
        $proxima = CrmTask::where('opportunity_id', $opportunityId)->whereNull('concluida_at')->orderBy('data')->value('data');
        $ultima  = CrmTask::where('opportunity_id', $opportunityId)->whereNotNull('concluida_at')->max('concluida_at');
        $o->update(['proxima_acao_at' => $proxima, 'ultima_interacao_at' => $ultima]);
    }
}
