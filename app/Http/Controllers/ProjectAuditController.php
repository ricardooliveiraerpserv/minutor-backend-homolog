<?php

namespace App\Http\Controllers;

use App\Models\ContractType;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Auditoria completa dos projetos: unifica project_change_logs (todas as alterações de campo),
 * edições de aporte (hour_contribution_change_logs) e lançamentos de aporte (hour_contributions).
 */
class ProjectAuditController extends Controller
{
    // Rótulos legíveis por campo. Campos fora daqui caem no fallback humanizado.
    private const FIELD_LABELS = [
        'name' => 'Nome', 'code' => 'Código', 'description' => 'Descrição', 'status' => 'Status',
        'customer_id' => 'Cliente', 'parent_project_id' => 'Projeto pai', 'contract_id' => 'Contrato',
        'contract_request_id' => 'Requisição', 'service_type_id' => 'Tipo de serviço', 'contract_type_id' => 'Tipo de contrato',
        'project_value' => 'Valor do projeto', 'hourly_rate' => 'Valor/hora', 'sold_hours' => 'Horas vendidas',
        'hour_contribution' => 'Aporte de horas', 'exceeded_hour_contribution' => 'Horas excedentes',
        'consultant_hours' => 'Horas consultor', 'coordinator_hours' => 'Horas coordenador',
        'coordinator_percentage' => '% Coordenador', 'coordination_hours' => 'Horas de coordenação',
        'additional_hourly_rate' => 'Valor/hora adicional', 'charge_excess_hours' => 'Cobra horas excedentes',
        'start_date' => 'Data de início', 'expected_end_date' => 'Previsão de término', 'encerramento_date' => 'Data de encerramento',
        'delivery_percentage' => '% Entrega', 'max_expense_per_consultant' => 'Limite de despesa/consultor',
        'unlimited_expense' => 'Despesa ilimitada', 'expense_responsible_party' => 'Responsável pela despesa',
        'limite_despesa' => 'Limite de despesa', 'cobra_despesa_cliente' => 'Cobra despesa do cliente',
        'tipo_faturamento' => 'Tipo de faturamento', 'tipo_alocacao' => 'Tipo de alocação',
        'architect_id' => 'Arquiteto', 'executivo_conta_id' => 'Executivo de conta', 'vendedor_id' => 'Vendedor',
        'condicao_pagamento' => 'Condição de pagamento', 'observacoes_contrato' => 'Observações do contrato',
        'allow_manual_timesheets' => 'Permite apontamento manual', 'allow_negative_balance' => 'Permite saldo negativo',
        'client_follows_timesheets' => 'Cliente acompanha apontamentos', 'extrato_visivel_cliente' => 'Extrato visível ao cliente',
        'initial_hours_balance' => 'Saldo inicial de horas', 'initial_hours_consumed' => 'Horas iniciais consumidas',
        'initial_cost' => 'Custo inicial', 'kanban_coordinator_override_id' => 'Coordenador (Kanban)',
        'is_investimento_comercial' => 'Investimento comercial', 'categoria_interna' => 'Categoria interna',
        'movidesk_integration_enabled' => 'Integração Movidesk', 'timesheet_retroactive_limit_days' => 'Limite retroativo de apontamento (dias)',
        'aporte_criado' => 'Aporte lançado',
    ];

    private const STATUS_LABELS = [
        'awaiting_start' => 'Aguardando início', 'planning' => 'Planejamento', 'started' => 'Em andamento',
        'liberado_para_testes' => 'Liberado para testes', 'em_producao' => 'Em produção', 'paused' => 'Pausado',
        'cancelled' => 'Cancelado', 'finished' => 'Encerrado',
    ];

    private const BOOL_FIELDS = [
        'charge_excess_hours', 'unlimited_expense', 'cobra_despesa_cliente', 'allow_manual_timesheets',
        'allow_negative_balance', 'client_follows_timesheets', 'extrato_visivel_cliente', 'save_erpserv',
        'is_manual_code', 'is_investimento_comercial', 'movidesk_integration_enabled',
    ];

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        if (!$u || !$u->isAdmin()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $search   = trim((string) $request->query('search', ''));
        $field    = trim((string) $request->query('field', ''));
        $userId   = $request->query('user_id');
        $source   = $request->query('source', 'todos'); // todos | projeto | aporte
        $from     = $request->query('date_from');
        $to       = $request->query('date_to');
        $page     = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(10, (int) $request->query('pageSize', 30)));
        $view      = $request->query('view', 'feed');       // 'projects' = lista de projetos com histórico
        $projectId = (int) $request->query('project_id', 0); // detalhe de UM projeto

        // Lista mestre: só os projetos que TÊM alteração/aporte (nunca traz quem não mudou).
        if ($view === 'projects') {
            return $this->projectsSummary($search);
        }

        $projIdsBySearch = null;
        if ($projectId > 0) {
            $projIdsBySearch = [$projectId];
        } elseif ($search !== '') {
            $projIdsBySearch = Project::where('code', 'ilike', "%{$search}%")
                ->orWhere('name', 'ilike', "%{$search}%")->pluck('id')->all();
            if (empty($projIdsBySearch)) {
                return response()->json(['items' => [], 'total' => 0, 'page' => $page, 'pageSize' => $pageSize, 'hasNext' => false]);
            }
        }

        $rows = collect();

        // 1) Alterações de campo do projeto
        if ($source !== 'aporte') {
            $q = DB::table('project_change_logs as l')
                ->join('projects as p', 'p.id', '=', 'l.project_id')
                ->leftJoin('users as u', 'u.id', '=', 'l.changed_by')
                ->select('l.project_id', 'p.code', 'p.name', 'l.field_name', 'l.old_value', 'l.new_value', 'l.changed_by', 'u.name as user_name', 'l.created_at');
            if ($projIdsBySearch !== null) $q->whereIn('l.project_id', $projIdsBySearch);
            if ($field !== '')   $q->where('l.field_name', $field);
            if ($userId)         $q->where('l.changed_by', $userId);
            if ($from)           $q->whereDate('l.created_at', '>=', $from);
            if ($to)             $q->whereDate('l.created_at', '<=', $to);
            foreach ($q->orderByDesc('l.created_at')->limit(5000)->get() as $r) {
                $rows->push(['source' => 'projeto', 'project_id' => $r->project_id, 'code' => $r->code, 'name' => $r->name,
                    'field_name' => $r->field_name, 'old_value' => $r->old_value, 'new_value' => $r->new_value,
                    'user_name' => $r->user_name, 'at' => $r->created_at]);
            }
        }

        // 2) Aporte — edições + lançamentos (só quando não filtra por um campo específico de projeto)
        if ($source !== 'projeto' && $field === '') {
            $qe = DB::table('hour_contribution_change_logs as l')
                ->join('projects as p', 'p.id', '=', 'l.project_id')
                ->leftJoin('users as u', 'u.id', '=', 'l.changed_by')
                ->select('l.project_id', 'p.code', 'p.name', 'l.field_name', 'l.old_value', 'l.new_value', 'u.name as user_name', 'l.created_at');
            if ($projIdsBySearch !== null) $qe->whereIn('l.project_id', $projIdsBySearch);
            if ($userId) $qe->where('l.changed_by', $userId);
            if ($from)   $qe->whereDate('l.created_at', '>=', $from);
            if ($to)     $qe->whereDate('l.created_at', '<=', $to);
            foreach ($qe->orderByDesc('l.created_at')->limit(5000)->get() as $r) {
                $rows->push(['source' => 'aporte', 'project_id' => $r->project_id, 'code' => $r->code, 'name' => $r->name,
                    'field_name' => 'aporte_' . $r->field_name, 'old_value' => $r->old_value, 'new_value' => $r->new_value,
                    'user_name' => $r->user_name, 'at' => $r->created_at]);
            }

            $qc = DB::table('hour_contributions as c')
                ->join('projects as p', 'p.id', '=', 'c.project_id')
                ->leftJoin('users as u', 'u.id', '=', 'c.contributed_by')
                ->select('c.project_id', 'p.code', 'p.name', 'c.contributed_hours', 'c.motivo', 'c.description', 'u.name as user_name', 'c.created_at');
            if ($projIdsBySearch !== null) $qc->whereIn('c.project_id', $projIdsBySearch);
            if ($userId) $qc->where('c.contributed_by', $userId);
            if ($from)   $qc->whereDate('c.created_at', '>=', $from);
            if ($to)     $qc->whereDate('c.created_at', '<=', $to);
            foreach ($qc->orderByDesc('c.created_at')->limit(5000)->get() as $r) {
                $rows->push(['source' => 'aporte', 'project_id' => $r->project_id, 'code' => $r->code, 'name' => $r->name,
                    'field_name' => 'aporte_criado', 'old_value' => null, 'new_value' => (string) $r->contributed_hours . 'h' . ($r->motivo ? ' (' . $r->motivo . ')' : '') . ($r->description ? ' — ' . $r->description : ''),
                    'user_name' => $r->user_name, 'at' => $r->created_at]);
            }
        }

        $sorted = $rows->sortByDesc('at')->values();
        $total  = $sorted->count();
        $slice  = $sorted->slice(($page - 1) * $pageSize, $pageSize)->values();

        $items = $slice->map(fn ($r) => [
            'source'      => $r['source'],
            'project_id'  => $r['project_id'],
            'project'     => trim(($r['code'] ?? '') . ($r['name'] ? ' — ' . $r['name'] : '')),
            'field'       => $r['field_name'],
            'field_label' => $this->fieldLabel($r['field_name']),
            'old'         => $this->resolveValue($r['field_name'], $r['old_value']),
            'new'         => $this->resolveValue($r['field_name'], $r['new_value']),
            'user'        => $r['user_name'] ?? '—',
            'at'          => $r['at'],
        ])->all();

        return response()->json([
            'items' => $items, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize,
            'hasNext' => ($page * $pageSize) < $total,
            'fields' => $this->availableFields(),
        ]);
    }

    /** Lista mestre: projetos que têm histórico (alterações + aportes), com contagem e data da última. */
    private function projectsSummary(string $search): JsonResponse
    {
        $acc = []; // project_id => ['count'=>n, 'last'=>ts]
        $add = function ($rows) use (&$acc) {
            foreach ($rows as $r) {
                $pid = (int) $r->project_id;
                if (!isset($acc[$pid])) $acc[$pid] = ['count' => 0, 'last' => null];
                $acc[$pid]['count'] += (int) $r->c;
                if ($r->last && (!$acc[$pid]['last'] || $r->last > $acc[$pid]['last'])) $acc[$pid]['last'] = $r->last;
            }
        };
        $add(DB::table('project_change_logs')->selectRaw('project_id, count(*) c, max(created_at) last')->groupBy('project_id')->get());
        $add(DB::table('hour_contribution_change_logs')->selectRaw('project_id, count(*) c, max(created_at) last')->groupBy('project_id')->get());
        $add(DB::table('hour_contributions')->selectRaw('project_id, count(*) c, max(created_at) last')->groupBy('project_id')->get());

        if (empty($acc)) return response()->json(['items' => []]);

        $projects = Project::whereIn('id', array_keys($acc))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%")))
            ->get(['id', 'code', 'name', 'customer_id']);
        $custNames = Customer::whereIn('id', $projects->pluck('customer_id')->filter()->unique())->pluck('name', 'id');

        $items = $projects->map(fn ($p) => [
            'project_id' => $p->id,
            'code'       => $p->code,
            'name'       => $p->name,
            'customer'   => $custNames[$p->customer_id] ?? null,
            'changes'    => $acc[$p->id]['count'] ?? 0,
            'last_at'    => $acc[$p->id]['last'] ?? null,
        ])->sortByDesc('last_at')->values()->all();

        return response()->json(['items' => $items]);
    }

    private function fieldLabel(string $field): string
    {
        if (isset(self::FIELD_LABELS[$field])) return self::FIELD_LABELS[$field];
        if (str_starts_with($field, 'aporte_')) return 'Aporte: ' . ucfirst(str_replace('_', ' ', substr($field, 7)));
        return ucfirst(str_replace('_', ' ', $field));
    }

    /** Resolve o valor bruto para algo legível (FK → nome, bool → Sim/Não, status → rótulo). */
    private function resolveValue(string $field, $value): ?string
    {
        if ($value === null || $value === '') return null;
        if ($field === 'status') return self::STATUS_LABELS[$value] ?? (string) $value;
        if (in_array($field, self::BOOL_FIELDS, true)) return ($value === '1' || $value === 1 || $value === true) ? 'Sim' : 'Não';

        $id = is_numeric($value) ? (int) $value : null;
        if ($id !== null) {
            switch ($field) {
                case 'contract_type_id':   return ContractType::find($id)?->name ?? "#{$id}";
                case 'service_type_id':    return ServiceType::find($id)?->name ?? "#{$id}";
                case 'customer_id':        return Customer::find($id)?->name ?? "#{$id}";
                case 'architect_id':
                case 'executivo_conta_id':
                case 'vendedor_id':
                case 'kanban_coordinator_override_id': return User::find($id)?->name ?? "#{$id}";
                case 'parent_project_id':  return Project::find($id)?->code ?? "#{$id}";
                case 'contract_id':        return "Contrato #{$id}";
                case 'contract_request_id':return "Requisição #{$id}";
            }
        }
        return (string) $value;
    }

    /** Lista de campos distintos já logados (p/ o filtro do FE). */
    private function availableFields(): array
    {
        $fields = DB::table('project_change_logs')->distinct()->pluck('field_name')->all();
        sort($fields);
        return array_map(fn ($f) => ['value' => $f, 'label' => $this->fieldLabel($f)], $fields);
    }
}
