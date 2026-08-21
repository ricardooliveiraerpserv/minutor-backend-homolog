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

    /**
     * Agenda global de Tarefas (conceito RD Station): todas as tarefas com situação
     * (pendente/atrasada/concluída), filtros e contadores. Paginada.
     */
    public function agenda(Request $request): JsonResponse
    {
        $request->validate([
            'status'         => 'nullable|in:abertas,pendentes,atrasadas,concluidas,todas',
            'responsavel_id' => 'nullable|exists:users,id',
            'customer_id'    => 'nullable|exists:customers,id',
            'tipo'           => 'nullable|string|max:60',
            'periodo'        => 'nullable|in:hoje,amanha,semana,sem_data',
            'de'             => 'nullable|date',
            'ate'            => 'nullable|date',
            'q'              => 'nullable|string|max:120',
            'page'           => 'nullable|integer|min:1',
        ]);

        // Base: aplica todos os filtros EXCETO status (p/ os contadores baterem).
        $base = fn () => CrmTask::query()
            ->when($request->filled('responsavel_id'), fn ($q) => $q->where('responsavel_id', $request->responsavel_id))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->customer_id))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->tipo))
            ->when($request->filled('de'), fn ($q) => $q->whereDate('data', '>=', $request->de))
            ->when($request->filled('ate'), fn ($q) => $q->whereDate('data', '<=', $request->ate))
            ->when($request->input('periodo') === 'hoje', fn ($q) => $q->whereDate('data', now()->toDateString()))
            ->when($request->input('periodo') === 'amanha', fn ($q) => $q->whereDate('data', now()->addDay()->toDateString()))
            ->when($request->input('periodo') === 'semana', fn ($q) => $q->whereBetween('data', [now()->startOfDay(), now()->addDays(7)->endOfDay()]))
            ->when($request->input('periodo') === 'sem_data', fn ($q) => $q->whereNull('data'))
            ->when($request->filled('q'), function ($q) use ($request) {
                $t = '%' . $request->q . '%';
                $q->where(fn ($w) => $w->where('titulo', 'ilike', $t)
                    ->orWhereHas('opportunity', fn ($o) => $o->where('title', 'ilike', $t))
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', $t)));
            });

        $now = now();
        $resumo = [
            'pendentes'  => (clone $base())->whereNull('concluida_at')->where(fn ($q) => $q->whereNull('data')->orWhere('data', '>=', $now))->count(),
            'atrasadas'  => (clone $base())->whereNull('concluida_at')->whereNotNull('data')->where('data', '<', $now)->count(),
            'concluidas' => (clone $base())->whereNotNull('concluida_at')->count(),
        ];

        $status = $request->input('status', 'abertas');
        $query = $base()->with('responsavel:id,name', 'opportunity:id,title,customer_id', 'opportunity.customer:id,name', 'customer:id,name');
        match ($status) {
            'pendentes'  => $query->whereNull('concluida_at')->where(fn ($q) => $q->whereNull('data')->orWhere('data', '>=', $now)),
            'atrasadas'  => $query->whereNull('concluida_at')->whereNotNull('data')->where('data', '<', $now),
            'concluidas' => $query->whereNotNull('concluida_at'),
            'todas'      => null,
            default      => $query->whereNull('concluida_at'), // abertas
        };
        $query->orderByRaw('concluida_at IS NULL DESC')
            ->orderByRaw('data IS NULL, data ' . ($status === 'concluidas' ? 'DESC' : 'ASC'));

        $p = $query->paginate(50, ['*'], 'page', (int) $request->input('page', 1));

        $tasks = collect($p->items())->map(function (CrmTask $t) use ($now) {
            $situacao = $t->concluida_at ? 'concluida'
                : (($t->data && $t->data->lt($now)) ? 'atrasada' : 'pendente');
            return [
                'id'          => $t->id,
                'tipo'        => $t->tipo,
                'titulo'      => $t->titulo,
                'data'        => optional($t->data)->toIso8601String(),
                'prioridade'  => $t->prioridade,
                'situacao'    => $situacao,
                'concluida'   => (bool) $t->concluida_at,
                'notas'       => $t->notas,
                'responsavel'    => $t->responsavel?->name,
                'responsavel_id' => $t->responsavel_id,
                'opportunity' => $t->opportunity ? ['id' => $t->opportunity->id, 'title' => $t->opportunity->title] : null,
                'empresa'     => $t->opportunity?->customer?->name ?? $t->customer?->name,
            ];
        });

        return response()->json([
            'data'     => $tasks,
            'resumo'   => $resumo,
            'page'     => $p->currentPage(),
            'has_more' => $p->hasMorePages(),
            'total'    => $p->total(),
        ]);
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
            'objetivo'       => 'nullable|string|max:200',
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

    /** Conclui (ou reabre) uma tarefa. Ambos geram log na Timeline. */
    public function complete(Request $request, CrmTask $crmTask): JsonResponse
    {
        $done = $request->boolean('done', true);
        $crmTask->update(['concluida_at' => $done ? now() : null]);
        if ($crmTask->opportunity_id) {
            CrmOpportunityEvent::log($crmTask->opportunity_id, $done ? 'task_done' : 'task_reopened', ['to_value' => $crmTask->titulo ?: $crmTask->tipo]);
            // Motivo da parada EXPIRA com nova interação (a oportunidade voltou a se mover).
            if ($done) $this->expirarMotivoParada($crmTask->opportunity_id);
            $this->recompute($crmTask->opportunity_id);
        }
        return response()->json(['data' => $crmTask->fresh()->load('responsavel:id,name')]);
    }

    /** Edita uma tarefa (tipo/título/data/etc) — gera log na Timeline. */
    public function update(Request $request, CrmTask $crmTask): JsonResponse
    {
        $v = $request->validate([
            'tipo'       => 'sometimes|string|max:60',
            'titulo'     => 'nullable|string|max:180',
            'objetivo'   => 'nullable|string|max:200',
            'data'       => 'nullable|date',
            'categoria'  => 'nullable|in:' . implode(',', CrmTask::CATEGORIAS),
            'prioridade' => 'nullable|in:baixa,media,alta',
            'notas'      => 'nullable|string',
        ]);
        $crmTask->update($v);
        if ($crmTask->opportunity_id) {
            CrmOpportunityEvent::log($crmTask->opportunity_id, 'task_updated', ['to_value' => $crmTask->titulo ?: $crmTask->tipo]);
            $this->recompute($crmTask->opportunity_id);
        }
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

    /** Limpa o motivo da parada quando há nova interação (deixa de estar "travada"). */
    private function expirarMotivoParada(int $opportunityId): void
    {
        $o = CrmOpportunity::find($opportunityId);
        if (!$o || !$o->motivo_parada) return;
        CrmOpportunityEvent::log($opportunityId, 'parada_alterada', [
            'field' => 'motivo_parada', 'from_value' => $o->motivo_parada, 'to_value' => null,
            'meta' => ['motivo' => 'expirou automaticamente (nova interação)'],
        ]);
        $o->update(['motivo_parada' => null, 'parada_em' => null]);
    }
}
