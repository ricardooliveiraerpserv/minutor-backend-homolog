<?php

namespace App\Http\Controllers;

use App\Models\CrmOpportunity;
use App\Models\CrmPipelineStage;
use App\Models\CrmSalesTarget;
use App\Models\CrmSalesTargetHistory;
use App\Models\CrmSalesTeam;
use App\Models\CrmCommissionRate;
use App\Models\CrmCommission;
use App\Models\CrmCommissionPolicy;
use App\Models\User;
use App\Services\PolicyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;

/**
 * CRM — telas financeiras da Política Comercial: Metas, Comissões, Rentabilidade.
 * Cada endpoint é gateado pela capacidade correspondente (goals.view/commission.view/profit.view).
 * "Realizado" é derivado das oportunidades GANHAS (status=ganho) no mês de fechamento_at;
 * o custo da rentabilidade mora em crm_opportunities.detalhes->custo (sem coluna nova).
 */
class CrmFinanceController extends Controller
{
    use \App\Http\Traits\FiltersByActiveCompany;

    public function __construct(private PolicyResolver $resolver) {}

    private function companyId(): ?int { return $this->activeCompanyId(); }

    /** Competência 'YYYY-MM' da query (default: mês atual). */
    private function comp(Request $r): string
    {
        $c = (string) $r->query('competencia');
        return preg_match('/^\d{4}-\d{2}$/', $c) ? $c : now()->format('Y-m');
    }

    /** Somente quem foi marcado como Responsável comercial (flag is_crm_responsavel). */
    private function responsaveis(): Collection
    {
        return User::query()
            ->where('is_crm_responsavel', true)
            ->orderBy('name')->get(['id', 'name', 'type']);
    }

    /** Realizado (R$ ganho) e qtd por responsavel_id na competência. */
    private function realizadoPorResp(string $comp): Collection
    {
        [$y, $m] = explode('-', $comp);
        return CrmOpportunity::where('status', 'ganho')
            ->whereYear('fechamento_at', (int) $y)->whereMonth('fechamento_at', (int) $m)
            ->selectRaw('responsavel_id, sum(valor) as total, count(*) as qtd')
            ->groupBy('responsavel_id')->get()->keyBy('responsavel_id');
    }

    /** Filtra a lista de responsáveis pelo escopo da capacidade (admin/all/team=aberto; own=só o seu; none=vazio). */
    private function applyScope(Collection $rows, string $key, ?User $u): Collection
    {
        if (!$u || $u->isAdmin()) return $rows;
        $scope = $this->resolver->scope($u, 'crm', $key, 'all');
        if ($scope === 'none') return collect();
        if ($scope === 'own')  return $rows->where('id', $u->id)->values();
        if ($scope === 'team') { $vis = CrmSalesTeam::visibleUserIds($u); return $rows->whereIn('id', $vis)->values(); }
        return $rows; // all/assigned → aberto
    }

    /** IDs de membros (+ gestor) de uma equipe, para o filtro "por equipe". */
    private function teamMemberIds(int $teamId): Collection
    {
        $t = CrmSalesTeam::with('members:id')->find($teamId);
        if (!$t) return collect();
        return $t->members->pluck('id')->push($t->manager_id)->filter()->unique()->values();
    }

    /** Equipes que o usuário pode filtrar (admin/gestor de política = todas; senão as suas). */
    private function teamsFor(?User $u): Collection
    {
        if (!$u) return collect();
        if ($u->isAdmin() || $u->type === 'administrativo' || $this->resolver->can($u, 'crm', 'policy.manage'))
            return CrmSalesTeam::where('active', true)->orderBy('name')->get(['id', 'name']);
        return CrmSalesTeam::where('active', true)
            ->where(fn ($q) => $q->where('manager_id', $u->id)->orWhereHas('members', fn ($m) => $m->where('users.id', $u->id)))
            ->orderBy('name')->get(['id', 'name']);
    }

    /** Pode editar (definir metas/percentuais): admin, administrativo, policy.manage ou escopo team/all. */
    private function canEditScope(?User $u, string $key): bool
    {
        if (!$u) return false;
        if ($u->isAdmin() || $u->type === 'administrativo' || $this->resolver->can($u, 'crm', 'policy.manage')) return true;
        return in_array($this->resolver->scope($u, 'crm', $key, 'all'), ['team', 'all'], true);
    }

    // ── METAS ────────────────────────────────────────────────────────────────
    public const META_TIPOS = ['receita', 'margem', 'quantidade', 'novos_clientes', 'receita_recorrente', 'receita_projeto', 'receita_sustentacao'];

    /** Realizado conforme o tipo da meta (receita=R$, margem=R$-custo, quantidade/novos=contagem). */
    private function realizadoTipo(Collection $won, string $tipo): float
    {
        return match ($tipo) {
            'quantidade' => (float) $won->count(),
            'novos_clientes' => (float) $won->where('tipo', 'novo_cliente')->count(),
            'margem' => (float) $won->sum(fn ($o) => (float) $o->valor - (float) (($o->detalhes['custo'] ?? 0))),
            default => (float) $won->sum('valor'), // receita e sub-tipos de receita
        };
    }

    public function metas(Request $r): JsonResponse
    {
        $u = $r->user();
        if ($u && !$u->isAdmin() && $this->resolver->scope($u, 'crm', 'goals.view', 'all') === 'none')
            abort(403, 'Seu perfil não permite ver metas.');
        $comp = $this->comp($r);
        [$y, $m] = array_map('intval', explode('-', $comp));
        $start = Carbon::create($y, $m, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();
        $resp = $this->applyScope($this->responsaveis(), 'goals.view', $u);
        $ids = $resp->pluck('id');
        $goals = CrmSalesTarget::where('periodo', $comp)->whereNotNull('user_id')->get()->keyBy('user_id');
        $won = $ids->isEmpty() ? collect() : CrmOpportunity::where('status', 'ganho')
            ->whereBetween('fechamento_at', [$start, $end])->whereIn('responsavel_id', $ids)
            ->get(['responsavel_id', 'valor', 'tipo', 'detalhes']);
        $wonByResp = $won->groupBy('responsavel_id');
        $ultima = $ids->isEmpty() ? collect() : CrmSalesTargetHistory::where('periodo', $comp)->whereIn('user_id', $ids)
            ->selectRaw('user_id, max(created_at) as last')->groupBy('user_id')->pluck('last', 'user_id');

        $rows = $resp->map(function ($x) use ($goals, $wonByResp, $ultima) {
            $g = $goals[$x->id] ?? null;
            $meta = (float) ($g->valor_meta ?? 0);
            $tipo = $g->tipo ?? 'receita';
            $realizado = $this->realizadoTipo($wonByResp->get($x->id) ?? collect(), $tipo);
            return [
                'user_id' => $x->id, 'name' => $x->name, 'meta' => $meta, 'tipo' => $tipo,
                'observacao' => $g->observacao ?? null, 'realizado' => $realizado,
                'qtd' => ($wonByResp->get($x->id) ?? collect())->count(),
                'pct' => $meta > 0 ? round($realizado / $meta * 100, 1) : null,
                'ultima_alteracao' => $ultima->get($x->id) ? Carbon::parse($ultima->get($x->id))->toDateTimeString() : null,
            ];
        })->values();
        // Totais só de metas de receita (não misturar R$ com quantidade)
        $recTipos = ['receita', 'receita_recorrente', 'receita_projeto', 'receita_sustentacao'];
        $recRows = $rows->whereIn('tipo', $recTipos);
        return response()->json(['data' => [
            'competencia' => $comp, 'can_edit' => $this->canEditScope($u, 'goals.view'),
            'total_meta' => (float) $recRows->sum('meta'), 'total_realizado' => (float) $recRows->sum('realizado'),
            'rows' => $rows,
        ]]);
    }

    public function setMeta(Request $r): JsonResponse
    {
        abort_unless($this->canEditScope($r->user(), 'goals.view'), 403, 'Sem permissão para definir metas.');
        $v = $r->validate([
            'user_id' => 'required|exists:users,id',
            'competencia' => 'required|regex:/^\d{4}-\d{2}$/',
            'valor_meta' => 'required|numeric|min:0',
            'tipo' => 'nullable|in:' . implode(',', self::META_TIPOS),
            'observacao' => 'nullable|string|max:500',
            'modo' => 'nullable|in:substituir,somar',
            'replicar_meses' => 'nullable|integer|min:0|max:11',
        ]);
        $tipo = $v['tipo'] ?? 'receita';
        $modo = $v['modo'] ?? 'substituir';
        $meses = (int) ($v['replicar_meses'] ?? 0);
        $saved = [];
        for ($k = 0; $k <= $meses; $k++) {
            $periodo = Carbon::createFromFormat('Y-m', $v['competencia'])->startOfMonth()->addMonthsNoOverflow($k)->format('Y-m');
            $existing = CrmSalesTarget::where('periodo', $periodo)->where('user_id', $v['user_id'])->first();
            $anterior = $existing ? (float) $existing->valor_meta : null;
            $novo = $modo === 'somar' ? (($anterior ?? 0) + (float) $v['valor_meta']) : (float) $v['valor_meta'];
            $target = CrmSalesTarget::updateOrCreate(
                ['periodo' => $periodo, 'user_id' => $v['user_id']],
                ['valor_meta' => $novo, 'tipo' => $tipo, 'observacao' => $v['observacao'] ?? null, 'created_by_id' => auth()->id()]
            );
            CrmSalesTargetHistory::create([
                'target_id' => $target->id, 'user_id' => $v['user_id'], 'periodo' => $periodo, 'tipo' => $tipo,
                'valor_anterior' => $anterior, 'valor_novo' => $novo, 'observacao' => $v['observacao'] ?? null,
                'changed_by_id' => auth()->id(), 'created_at' => now(),
            ]);
            $saved[] = $periodo;
        }
        return response()->json(['data' => ['periodos' => $saved]]);
    }

    /** Histórico de alterações de meta (auditoria). */
    public function metasHistorico(Request $r): JsonResponse
    {
        $u = $r->user();
        if ($u && !$u->isAdmin() && $this->resolver->scope($u, 'crm', 'goals.view', 'all') === 'none')
            abort(403, 'Seu perfil não permite ver metas.');
        $resp = $this->applyScope($this->responsaveis(), 'goals.view', $u);
        $ids = $resp->pluck('id');
        $names = $resp->pluck('name', 'id');
        $q = CrmSalesTargetHistory::whereIn('user_id', $ids)->with('changedBy:id,name')->orderByDesc('created_at')->limit(200);
        if ($uid = (int) $r->query('user_id')) $q->where('user_id', $uid);
        if ($p = $r->query('competencia')) $q->where('periodo', $p);
        $rows = $q->get()->map(fn ($h) => [
            'id' => $h->id, 'responsavel' => $names[$h->user_id] ?? '—', 'periodo' => $h->periodo, 'tipo' => $h->tipo,
            'valor_anterior' => $h->valor_anterior !== null ? (float) $h->valor_anterior : null,
            'valor_novo' => (float) $h->valor_novo, 'observacao' => $h->observacao,
            'por' => $h->changedBy?->name, 'em' => $h->created_at?->toDateTimeString(),
        ]);
        return response()->json(['data' => $rows->values()]);
    }

    /** Importação em massa de metas (rows: [{user_id, valor_meta}]). */
    public function importarMetas(Request $r): JsonResponse
    {
        abort_unless($this->canEditScope($r->user(), 'goals.view'), 403, 'Sem permissão para definir metas.');
        $v = $r->validate([
            'competencia' => 'required|regex:/^\d{4}-\d{2}$/',
            'tipo' => 'nullable|in:' . implode(',', self::META_TIPOS),
            'rows' => 'required|array|min:1',
            'rows.*.user_id' => 'required|integer|exists:users,id',
            'rows.*.valor_meta' => 'required|numeric|min:0',
        ]);
        $tipo = $v['tipo'] ?? 'receita';
        $n = 0;
        foreach ($v['rows'] as $row) {
            $existing = CrmSalesTarget::where('periodo', $v['competencia'])->where('user_id', $row['user_id'])->first();
            $anterior = $existing ? (float) $existing->valor_meta : null;
            $t = CrmSalesTarget::updateOrCreate(
                ['periodo' => $v['competencia'], 'user_id' => $row['user_id']],
                ['valor_meta' => $row['valor_meta'], 'tipo' => $tipo, 'created_by_id' => auth()->id()]
            );
            CrmSalesTargetHistory::create([
                'target_id' => $t->id, 'user_id' => $row['user_id'], 'periodo' => $v['competencia'], 'tipo' => $tipo,
                'valor_anterior' => $anterior, 'valor_novo' => (float) $row['valor_meta'], 'observacao' => 'Importação',
                'changed_by_id' => auth()->id(), 'created_at' => now(),
            ]);
            $n++;
        }
        return response()->json(['data' => ['importadas' => $n]]);
    }

    // ── COCKPIT (visão executiva de Metas) ────────────────────────────────────
    public function cockpit(Request $r): JsonResponse
    {
        $u = $r->user();
        if ($u && !$u->isAdmin() && $this->resolver->scope($u, 'crm', 'goals.view', 'all') === 'none')
            abort(403, 'Seu perfil não permite ver metas.');

        $comp = $this->comp($r);
        [$y, $m] = array_map('intval', explode('-', $comp));
        $start = Carbon::create($y, $m, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();
        $dim = $start->daysInMonth;
        $prev = (clone $start)->subMonthNoOverflow();
        $today = now();
        $diaCorrente = ($today->year === $y && $today->month === $m) ? $today->day : $dim;

        $resp = $this->applyScope($this->responsaveis(), 'goals.view', $u);
        if ($teamId = (int) $r->query('team_id')) $resp = $resp->whereIn('id', $this->teamMemberIds($teamId))->values();
        $ids = $resp->pluck('id');
        $cid = $this->companyId();

        $goals = CrmSalesTarget::where('periodo', $comp)->whereNotNull('user_id')->get()->keyBy('user_id');
        $goalsPrev = CrmSalesTarget::where('periodo', $prev->format('Y-m'))->whereNotNull('user_id')->get()->keyBy('user_id');

        // Oportunidades (escopo = responsáveis filtrados)
        $won = $ids->isEmpty() ? collect() : CrmOpportunity::where('status', 'ganho')
            ->whereBetween('fechamento_at', [$start, $end])->whereIn('responsavel_id', $ids)
            ->get(['id', 'title', 'responsavel_id', 'valor', 'fechamento_at']);
        $wonPrev = $ids->isEmpty() ? collect() : CrmOpportunity::where('status', 'ganho')
            ->whereYear('fechamento_at', $prev->year)->whereMonth('fechamento_at', $prev->month)
            ->whereIn('responsavel_id', $ids)->get(['responsavel_id', 'valor']);
        $lost = $ids->isEmpty() ? 0 : CrmOpportunity::where('status', 'perdido')
            ->whereBetween('fechamento_at', [$start, $end])->whereIn('responsavel_id', $ids)->count();
        $open = $ids->isEmpty() ? collect() : CrmOpportunity::where('status', 'aberto')
            ->whereIn('responsavel_id', $ids)->with('stage:id,name,ordem,probabilidade')
            ->get(['id', 'title', 'responsavel_id', 'valor', 'probabilidade', 'stage_id', 'ultima_interacao_at']);
        // Última venda (histórica) por responsável
        $lastSale = $ids->isEmpty() ? collect() : CrmOpportunity::where('status', 'ganho')->whereIn('responsavel_id', $ids)
            ->selectRaw('responsavel_id, max(fechamento_at) as last')->groupBy('responsavel_id')->pluck('last', 'responsavel_id');

        $wonByResp = $won->groupBy('responsavel_id');
        $wonPrevByResp = $wonPrev->groupBy('responsavel_id');
        $openByResp = $open->groupBy('responsavel_id');
        $peso = fn ($o) => (float) $o->valor * ((float) ($o->probabilidade ?? $o->stage?->probabilidade ?? 0) / 100);

        // Ranking por responsável
        $ranking = $resp->map(function ($x) use ($goals, $wonByResp, $openByResp, $lastSale, $peso) {
            $meta = (float) ($goals[$x->id]->valor_meta ?? 0);
            $w = $wonByResp->get($x->id) ?? collect();
            $realizado = (float) $w->sum('valor');
            $negocios = $w->count();
            $o = $openByResp->get($x->id) ?? collect();
            $pipeline = (float) $o->sum('valor');
            $forecast = $realizado + (float) $o->sum($peso);
            return [
                'user_id' => $x->id, 'name' => $x->name,
                'cargo' => $x->type,
                'meta' => $meta, 'realizado' => $realizado, 'negocios' => $negocios,
                'ticket' => $negocios ? round($realizado / $negocios, 2) : 0,
                'pipeline' => $pipeline, 'forecast' => round($forecast, 2),
                'pct' => $meta > 0 ? round($realizado / $meta * 100, 1) : null,
                'chance' => $meta > 0 ? round($forecast / $meta * 100, 1) : null,
                'ultima_venda' => optional($lastSale->get($x->id) ? Carbon::parse($lastSale->get($x->id)) : null)?->toDateString(),
            ];
        })->values();

        // KPIs (somatórios do escopo)
        $metaTotal = (float) $ranking->sum('meta');
        $realTotal = (float) $ranking->sum('realizado');
        $foreTotal = (float) $ranking->sum('forecast');
        $pipeTotal = (float) $ranking->sum('pipeline');
        $ganhos = (int) $ranking->sum('negocios');
        $ticket = $ganhos ? round($realTotal / $ganhos, 2) : 0;
        $metaPrev = 0.0; foreach ($ids as $i) $metaPrev += (float) ($goalsPrev[$i]->valor_meta ?? 0);
        $realPrev = (float) $wonPrev->sum('valor');
        $ganhosPrev = $wonPrev->count();
        $ticketPrev = $ganhosPrev ? round($realPrev / $ganhosPrev, 2) : 0;
        $delta = fn ($a, $b) => $b > 0 ? round(($a - $b) / $b * 100, 1) : null;

        // Evolução diária (acumulados)
        $wonByDay = $won->groupBy(fn ($o) => (int) Carbon::parse($o->fechamento_at)->day);
        $evolucao = [];
        $acum = 0.0;
        for ($d = 1; $d <= $dim; $d++) {
            $acum += (float) (($wonByDay->get($d) ?? collect())->sum('valor'));
            $evolucao[] = [
                'dia' => $d,
                'meta_acum' => round($metaTotal * $d / $dim, 2),
                'forecast_acum' => round($foreTotal * $d / $dim, 2),
                'realizado_acum' => $d <= $diaCorrente ? round($acum, 2) : null,
            ];
        }

        // Funil (oportunidades abertas por etapa)
        $funil = $open->groupBy('stage_id')->map(function ($grp) {
            $st = $grp->first()->stage;
            return ['stage' => $st?->name ?? '—', 'ordem' => (int) ($st?->ordem ?? 99),
                'count' => $grp->count(), 'valor' => (float) $grp->sum('valor')];
        })->sortBy('ordem')->values();
        $funilTotal = (float) $funil->sum('valor');
        $funil = $funil->map(fn ($f) => $f + ['pct' => $funilTotal > 0 ? round($f['valor'] / $funilTotal * 100, 1) : 0]);

        // Insights
        $abaixo50 = $ranking->filter(fn ($r) => $r['pct'] !== null && $r['pct'] < 50)->count();
        $melhor = $ranking->sortByDesc('realizado')->first();
        $maiorOpp = $open->sortByDesc('valor')->first();
        $paradas = $open->filter(fn ($o) => !$o->ultima_interacao_at || Carbon::parse($o->ultima_interacao_at)->lt(now()->subDays(15)))->count();

        return response()->json(['data' => [
            'competencia' => $comp, 'can_edit' => $this->canEditScope($u, 'goals.view'),
            'teams' => $this->teamsFor($u), 'team_id' => $teamId ?: null,
            'dias_mes' => $dim, 'dia_corrente' => $diaCorrente,
            'kpis' => [
                'meta' => $metaTotal, 'meta_delta' => $delta($metaTotal, $metaPrev),
                'realizado' => $realTotal, 'realizado_delta' => $delta($realTotal, $realPrev),
                'realizado_pct' => $metaTotal > 0 ? round($realTotal / $metaTotal * 100, 1) : null,
                'forecast' => $foreTotal, 'forecast_pct' => $metaTotal > 0 ? round($foreTotal / $metaTotal * 100, 1) : null,
                'falta' => max(0, round($metaTotal - $realTotal, 2)),
                'opps_necessarias' => $ticket > 0 ? (int) ceil(max(0, $metaTotal - $realTotal) / $ticket) : null,
                'ticket' => $ticket, 'ticket_delta' => $delta($ticket, $ticketPrev),
                'ganhos' => $ganhos, 'conversao' => ($ganhos + $lost) > 0 ? round($ganhos / ($ganhos + $lost) * 100, 1) : null,
                'pipeline' => $pipeTotal,
            ],
            'evolucao' => $evolucao,
            'funil' => $funil,
            'ranking' => $ranking,
            'insights' => [
                'abaixo_50' => $abaixo50,
                'forecast_pct' => $metaTotal > 0 ? round($foreTotal / $metaTotal * 100, 1) : null,
                'melhor' => $melhor && $melhor['realizado'] > 0 ? ['name' => $melhor['name'], 'valor' => $melhor['realizado']] : null,
                'maior_oportunidade' => $maiorOpp ? ['title' => $maiorOpp->title, 'valor' => (float) $maiorOpp->valor] : null,
                'pipeline_total' => $pipeTotal,
                'paradas_15d' => $paradas,
                'total_responsaveis' => $ranking->count(),
            ],
        ]]);
    }

    /** Duplica as metas do mês anterior para a competência informada (só quem não tem meta ainda). */
    public function duplicateMetas(Request $r): JsonResponse
    {
        abort_unless($this->canEditScope($r->user(), 'goals.view'), 403, 'Sem permissão para definir metas.');
        $to = $this->comp($r);
        $from = Carbon::createFromFormat('Y-m', $to)->startOfMonth()->subMonthNoOverflow()->format('Y-m');
        $prev = CrmSalesTarget::where('periodo', $from)->whereNotNull('user_id')->get();
        $n = 0;
        foreach ($prev as $g) {
            CrmSalesTarget::firstOrCreate(
                ['periodo' => $to, 'user_id' => $g->user_id],
                ['valor_meta' => $g->valor_meta, 'created_by_id' => auth()->id()]
            )->wasRecentlyCreated && $n++;
        }
        return response()->json(['data' => ['copiadas' => $n, 'de' => $from, 'para' => $to]]);
    }

    // ── COMISSÕES ───────────────────────────────────────────────────────────
    public function comissoes(Request $r): JsonResponse
    {
        $u = $r->user();
        if ($u && !$u->isAdmin() && $this->resolver->scope($u, 'crm', 'commission.view', 'all') === 'none')
            abort(403, 'Seu perfil não permite ver comissões.');
        $comp = $this->comp($r);
        $resp = $this->applyScope($this->responsaveis(), 'commission.view', $u);
        $real = $this->realizadoPorResp($comp);
        $rates = CrmCommissionRate::get();
        $default = (float) (optional($rates->firstWhere('user_id', null))->percentual ?? 0);
        $byUser = $rates->whereNotNull('user_id')->keyBy('user_id');
        $rows = $resp->map(function ($x) use ($real, $byUser, $default) {
            $base = (float) ($real[$x->id]->total ?? 0);
            $pct = $byUser->has($x->id) ? (float) $byUser[$x->id]->percentual : $default;
            return [
                'user_id' => $x->id, 'name' => $x->name,
                'base' => $base, 'percentual' => $pct, 'comissao' => round($base * $pct / 100, 2),
                'qtd' => (int) ($real[$x->id]->qtd ?? 0),
            ];
        })->values();
        return response()->json(['data' => [
            'competencia' => $comp, 'can_edit' => $this->canEditScope($u, 'commission.view'),
            'percentual_padrao' => $default,
            'total_base' => (float) $rows->sum('base'), 'total_comissao' => (float) $rows->sum('comissao'),
            'rows' => $rows,
        ]]);
    }

    public function setRate(Request $r): JsonResponse
    {
        abort_unless($this->canEditScope($r->user(), 'commission.view'), 403, 'Sem permissão para definir comissão.');
        $v = $r->validate([
            'user_id' => 'nullable|exists:users,id',
            'percentual' => 'required|numeric|min:0|max:100',
        ]);
        $rate = CrmCommissionRate::updateOrCreate(
            ['company_id' => $this->companyId(), 'user_id' => $v['user_id'] ?? null],
            ['percentual' => $v['percentual']]
        );
        return response()->json(['data' => $rate]);
    }

    // ── COCKPIT DE COMISSÕES ──────────────────────────────────────────────────
    public function comissoesCockpit(Request $r): JsonResponse
    {
        $u = $r->user();
        if ($u && !$u->isAdmin() && $this->resolver->scope($u, 'crm', 'commission.view', 'all') === 'none')
            abort(403, 'Seu perfil não permite ver comissões.');
        $comp = $this->comp($r);
        [$y, $m] = array_map('intval', explode('-', $comp));
        $start = Carbon::create($y, $m, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();
        $prev = (clone $start)->subMonthNoOverflow();
        $resp = $this->applyScope($this->responsaveis(), 'commission.view', $u);
        if ($teamId = (int) $r->query('team_id')) $resp = $resp->whereIn('id', $this->teamMemberIds($teamId))->values();
        $ids = $resp->pluck('id');
        $cid = $this->companyId();

        $rates = CrmCommissionRate::when($cid, fn ($q, $c) => $q->where('company_id', $c))->get();
        $default = (float) (optional($rates->firstWhere('user_id', null))->percentual ?? 0);
        $byUser = $rates->whereNotNull('user_id')->keyBy('user_id');
        $rateOf = fn ($uid) => $byUser->has($uid) ? (float) $byUser[$uid]->percentual : $default;
        $delta = fn ($a, $b) => $b > 0 ? round(($a - $b) / $b * 100, 1) : null;

        $won = $ids->isEmpty() ? collect() : CrmOpportunity::where('status', 'ganho')
            ->whereBetween('fechamento_at', [$start, $end])->whereIn('responsavel_id', $ids)
            ->get(['id', 'title', 'responsavel_id', 'valor', 'fechamento_at']);
        $open = $ids->isEmpty() ? collect() : CrmOpportunity::where('status', 'aberto')
            ->whereIn('responsavel_id', $ids)->with('stage:id,probabilidade')
            ->get(['id', 'responsavel_id', 'valor', 'probabilidade', 'stage_id']);
        $wonPrev = $ids->isEmpty() ? collect() : CrmOpportunity::where('status', 'ganho')
            ->whereYear('fechamento_at', $prev->year)->whereMonth('fechamento_at', $prev->month)
            ->whereIn('responsavel_id', $ids)->get(['responsavel_id', 'valor']);
        // 12 meses para a evolução
        $evStart = (clone $start)->subMonthsNoOverflow(11)->startOfMonth();
        $wonYear = $ids->isEmpty() ? collect() : CrmOpportunity::where('status', 'ganho')
            ->whereBetween('fechamento_at', [$evStart, $end])->whereIn('responsavel_id', $ids)
            ->get(['responsavel_id', 'valor', 'fechamento_at']);

        $wonByResp = $won->groupBy('responsavel_id');
        $openByResp = $open->groupBy('responsavel_id');
        $peso = fn ($o) => (float) $o->valor * ((float) ($o->probabilidade ?? $o->stage?->probabilidade ?? 0) / 100);

        $ranking = $resp->map(function ($x) use ($wonByResp, $openByResp, $rateOf, $peso) {
            $w = $wonByResp->get($x->id) ?? collect();
            $base = (float) $w->sum('valor');
            $neg = $w->count();
            $o = $openByResp->get($x->id) ?? collect();
            $pct = $rateOf($x->id);
            return [
                'user_id' => $x->id, 'name' => $x->name, 'cargo' => $x->type,
                'base' => $base, 'negocios' => $neg, 'ticket' => $neg ? round($base / $neg, 2) : 0,
                'percentual' => $pct, 'comissao' => round($base * $pct / 100, 2),
                'pipeline' => (float) $o->sum('valor'),
                'forecast_comissao' => round(((float) $o->sum($peso)) * $pct / 100, 2),
            ];
        })->sortByDesc('comissao')->values();

        $baseTotal = (float) $ranking->sum('base');
        $comTotal = (float) $ranking->sum('comissao');
        $ganhos = (int) $ranking->sum('negocios');
        $pipeTotal = (float) $ranking->sum('pipeline');
        $ticket = $ganhos ? round($baseTotal / $ganhos, 2) : 0;
        $basePrev = (float) $wonPrev->sum('valor');
        $comPrev = (float) $wonPrev->sum(fn ($o) => (float) $o->valor * $rateOf($o->responsavel_id) / 100);

        $evolucao = [];
        for ($k = 11; $k >= 0; $k--) {
            $mth = (clone $start)->subMonthsNoOverflow($k);
            $key = $mth->format('Y-m');
            $sum = (float) $wonYear->filter(fn ($o) => Carbon::parse($o->fechamento_at)->format('Y-m') === $key)
                ->sum(fn ($o) => (float) $o->valor * $rateOf($o->responsavel_id) / 100);
            $evolucao[] = ['mes' => $mth->format('m/y'), 'comissao' => round($sum, 2)];
        }

        $maiorVenda = $won->sortByDesc('valor')->first();
        $melhor = $ranking->first();

        // Ciclo de pagamento (lançamentos apurados no mês)
        $entries = $ids->isEmpty() ? collect() : CrmCommission::where('competencia', $comp)->whereIn('user_id', $ids)->get();
        $st = fn ($s) => (float) $entries->where('status', $s)->sum('valor');
        $pagamento = [
            'apurada' => $st('apurada'), 'aprovada' => $st('aprovada'), 'paga' => $st('paga'),
            'bloqueada' => $st('bloqueada'), 'cancelada' => $st('cancelada'),
            'pendente' => $st('apurada') + $st('aprovada'),
            'total_apurado' => (float) $entries->where('status', '!=', 'cancelada')->sum('valor'),
            'nao_apuradas' => $ganhos - $entries->count(), // negócios ganhos sem lançamento
            'count' => $entries->count(),
        ];
        $hasTracking = $entries->isNotEmpty();
        $distStatus = collect(['paga' => 'Paga', 'aprovada' => 'Aprovada', 'apurada' => 'Apurada', 'bloqueada' => 'Bloqueada'])
            ->map(fn ($lbl, $s) => ['name' => $lbl, 'valor' => $st($s)])->filter(fn ($x) => $x['valor'] > 0)->values();

        // Alertas automáticos (pendências operacionais)
        $cnt = fn ($s) => $entries->where('status', $s)->count();
        $alertas = [];
        if ($pagamento['nao_apuradas'] > 0) $alertas[] = ['nivel' => 'warning', 'texto' => $pagamento['nao_apuradas'] . ' negócio(s) ganho(s) ainda não apurado(s)'];
        if ($cnt('apurada') > 0) $alertas[] = ['nivel' => 'info', 'texto' => $cnt('apurada') . ' comissão(ões) aguardando aprovação'];
        if ($cnt('aprovada') > 0) $alertas[] = ['nivel' => 'info', 'texto' => $cnt('aprovada') . ' aprovada(s) aguardando pagamento'];
        if ($cnt('bloqueada') > 0) $alertas[] = ['nivel' => 'danger', 'texto' => $cnt('bloqueada') . ' comissão(ões) bloqueada(s)'];

        return response()->json(['data' => [
            'competencia' => $comp, 'can_edit' => $this->canEditScope($u, 'commission.view'),
            'teams' => $this->teamsFor($u), 'team_id' => $teamId ?: null,
            'percentual_padrao' => $default, 'has_payment_tracking' => $hasTracking,
            'pagamento' => $pagamento, 'distribuicao_status' => $distStatus, 'alertas' => $alertas,
            'kpis' => [
                'base' => $baseTotal, 'base_delta' => $delta($baseTotal, $basePrev),
                'comissao' => $comTotal, 'comissao_delta' => $delta($comTotal, $comPrev),
                'pct_faturamento' => $baseTotal > 0 ? round($comTotal / $baseTotal * 100, 2) : null,
                'ticket' => $ticket, 'ganhos' => $ganhos, 'pipeline' => $pipeTotal,
                'maior_comissao' => $melhor && $melhor['comissao'] > 0 ? ['name' => $melhor['name'], 'valor' => $melhor['comissao']] : null,
                'comissao_media' => $ranking->count() ? round($comTotal / $ranking->count(), 2) : 0,
                'forecast_comissao' => (float) $ranking->sum('forecast_comissao'),
            ],
            'evolucao' => $evolucao,
            'distribuicao' => $ranking->filter(fn ($x) => $x['comissao'] > 0)->map(fn ($x) => ['name' => $x['name'], 'valor' => $x['comissao']])->values(),
            'ranking' => $ranking,
            'insights' => [
                'maior_comissao' => $melhor && $melhor['comissao'] > 0 ? ['name' => $melhor['name'], 'valor' => $melhor['comissao']] : null,
                'maior_venda' => $maiorVenda ? ['title' => $maiorVenda->title, 'valor' => (float) $maiorVenda->valor] : null,
                'maior_ticket' => optional($ranking->sortByDesc('ticket')->first()),
                'maior_percentual' => optional($ranking->sortByDesc('percentual')->first()),
                'maior_pipeline' => optional($ranking->sortByDesc('pipeline')->first()),
                'comissao_media' => $ranking->count() ? round($comTotal / $ranking->count(), 2) : 0,
                'pendente' => $comTotal, // sem ciclo de pagamento ainda → tudo apurado
            ],
        ]]);
    }

    /** Apura (gera lançamentos de) comissão das oportunidades ganhas no mês ainda sem lançamento. */
    public function apurar(Request $r): JsonResponse
    {
        $u = $r->user();
        abort_unless($this->canEditScope($u, 'commission.view'), 403, 'Sem permissão para apurar comissões.');
        $comp = $this->comp($r);
        [$y, $m] = array_map('intval', explode('-', $comp));
        $start = Carbon::create($y, $m, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();
        $ids = $this->responsaveis()->pluck('id');
        $rates = CrmCommissionRate::when($this->companyId(), fn ($q, $c) => $q->where('company_id', $c))->get();
        $default = (float) (optional($rates->firstWhere('user_id', null))->percentual ?? 0);
        $byUser = $rates->whereNotNull('user_id')->keyBy('user_id');
        $rateOf = fn ($uid) => $byUser->has($uid) ? (float) $byUser[$uid]->percentual : $default;

        $won = $ids->isEmpty() ? collect() : CrmOpportunity::where('status', 'ganho')
            ->whereBetween('fechamento_at', [$start, $end])->whereIn('responsavel_id', $ids)
            ->get(['id', 'responsavel_id', 'valor', 'pipeline_id', 'tipo', 'detalhes']);
        $existing = CrmCommission::whereIn('opportunity_id', $won->pluck('id'))->pluck('opportunity_id')->flip();
        $cargos = User::whereIn('id', $won->pluck('responsavel_id')->filter()->unique())->pluck('type', 'id');
        // Atingimento de meta por vendedor (para políticas progressivas)
        $realizadoSeller = $won->groupBy('responsavel_id')->map(fn ($g) => (float) $g->sum('valor'));
        $metas = CrmSalesTarget::where('periodo', $comp)->whereNotNull('user_id')->get()->keyBy('user_id');
        $n = 0;
        foreach ($won as $o) {
            if ($existing->has($o->id) || !$o->responsavel_id) continue;
            $base = (float) $o->valor;
            $custo = (float) (($o->detalhes['custo'] ?? 0));
            $margem = $base > 0 ? round(($base - $custo) / $base * 100, 2) : null;
            $meta = (float) ($metas[$o->responsavel_id]->valor_meta ?? 0);
            $ating = $meta > 0 ? round(($realizadoSeller[$o->responsavel_id] ?? 0) / $meta * 100, 2) : null;
            // Política de comissão resolve o %; sem regra → cai no % do vendedor/padrão.
            [$pct] = CrmCommissionPolicy::resolve([
                'cargo' => $cargos[$o->responsavel_id] ?? null, 'pipeline_id' => $o->pipeline_id,
                'valor' => $base, 'margem' => $margem, 'tipo' => $o->tipo, 'atingimento' => $ating,
            ], $rateOf($o->responsavel_id));
            CrmCommission::create([
                'opportunity_id' => $o->id, 'user_id' => $o->responsavel_id, 'competencia' => $comp,
                'base' => $base, 'percentual' => $pct, 'valor' => round($base * $pct / 100, 2),
                'status' => 'apurada', 'created_by_id' => $u->id,
            ]);
            $n++;
        }
        return response()->json(['data' => ['apuradas' => $n, 'competencia' => $comp]]);
    }

    /** Lançamentos de comissão (por negócio) — drill-down e ações de status. */
    public function lancamentos(Request $r): JsonResponse
    {
        $u = $r->user();
        if ($u && !$u->isAdmin() && $this->resolver->scope($u, 'crm', 'commission.view', 'all') === 'none')
            abort(403, 'Seu perfil não permite ver comissões.');
        $comp = $this->comp($r);
        $resp = $this->applyScope($this->responsaveis(), 'commission.view', $u);
        if ($teamId = (int) $r->query('team_id')) $resp = $resp->whereIn('id', $this->teamMemberIds($teamId))->values();
        $ids = $resp->pluck('id');
        $q = CrmCommission::where('competencia', $comp)->whereIn('user_id', $ids)
            ->with(['opportunity:id,title,customer_id', 'opportunity.customer:id,name', 'user:id,name']);
        if ($status = $r->query('status')) $q->where('status', $status);
        if ($uid = (int) $r->query('user_id')) $q->where('user_id', $uid);
        $rows = $q->orderByDesc('valor')->get()->map(fn ($c) => [
            'id' => $c->id, 'negocio' => $c->opportunity?->title, 'cliente' => $c->opportunity?->customer?->name,
            'responsavel' => $c->user?->name, 'base' => (float) $c->base, 'percentual' => (float) $c->percentual,
            'valor' => (float) $c->valor, 'status' => $c->status,
            'aprovado_em' => $c->approved_at?->toDateString(), 'pago_em' => $c->paid_at?->toDateString(),
            'motivo' => $c->motivo, 'transicoes' => CrmCommission::TRANSITIONS[$c->status] ?? [],
        ]);
        return response()->json(['data' => [
            'competencia' => $comp, 'can_edit' => $this->canEditScope($u, 'commission.view'),
            'rows' => $rows->values(),
        ]]);
    }

    /** Transição de status de um lançamento (aprovar/pagar/bloquear/cancelar/desbloquear). */
    public function commissionStatus(Request $r, CrmCommission $commission): JsonResponse
    {
        $u = $r->user();
        abort_unless($this->canEditScope($u, 'commission.view'), 403, 'Sem permissão para alterar comissões.');
        $v = $r->validate(['status' => 'required|string', 'motivo' => 'nullable|string|max:200']);
        $to = $v['status'];
        abort_unless(in_array($to, CrmCommission::TRANSITIONS[$commission->status] ?? [], true), 422, "Transição {$commission->status} → {$to} não permitida.");
        $commission->status = $to;
        if ($to === 'aprovada') { $commission->approved_by_id = $u->id; $commission->approved_at = now(); }
        if ($to === 'paga') { $commission->paid_at = now(); if (!$commission->approved_at) { $commission->approved_at = now(); $commission->approved_by_id = $u->id; } }
        if (in_array($to, ['bloqueada', 'cancelada'], true)) $commission->motivo = $v['motivo'] ?? null;
        if ($to === 'apurada') $commission->motivo = null; // desbloqueio
        $commission->save();
        return response()->json(['data' => ['id' => $commission->id, 'status' => $to]]);
    }

    // ── RENTABILIDADE ─────────────────────────────────────────────────────────
    private function canSeeProfit(?User $u): bool
    {
        return $u && ($u->isAdmin() || $this->resolver->can($u, 'crm', 'profit.view'));
    }

    public function rentabilidade(Request $r): JsonResponse
    {
        $u = $r->user();
        abort_unless($this->canSeeProfit($u), 403, 'Seu perfil não permite ver rentabilidade.');
        $comp = $this->comp($r);
        [$y, $m] = explode('-', $comp);
        $q = CrmOpportunity::where('status', 'ganho')
            ->whereYear('fechamento_at', (int) $y)->whereMonth('fechamento_at', (int) $m)
            ->with(['customer:id,name', 'responsavel:id,name']);
        if (!$u->isAdmin()) {
            $os = $this->resolver->scope($u, 'crm', 'opp.view', 'all');
            if ($os === 'own') $q->where('responsavel_id', $u->id);
            elseif ($os === 'team') $q->whereIn('responsavel_id', CrmSalesTeam::visibleUserIds($u));
            elseif ($os === 'none') $q->whereRaw('1 = 0');
        }
        $rows = $q->orderByDesc('fechamento_at')->get()->map(function ($o) {
            $receita = (float) $o->valor;
            $custo = (float) (($o->detalhes['custo'] ?? 0));
            $lucro = $receita - $custo;
            return [
                'id' => $o->id, 'title' => $o->title,
                'cliente' => $o->customer?->name, 'responsavel' => $o->responsavel?->name,
                'fechamento_at' => $o->fechamento_at?->toDateString(),
                'receita' => $receita, 'custo' => $custo, 'lucro' => $lucro,
                'margem' => $receita > 0 ? round($lucro / $receita * 100, 1) : null,
            ];
        });
        return response()->json(['data' => [
            'competencia' => $comp, 'can_edit' => $this->canSeeProfit($u),
            'total_receita' => (float) $rows->sum('receita'), 'total_custo' => (float) $rows->sum('custo'),
            'total_lucro' => (float) $rows->sum('lucro'), 'rows' => $rows,
        ]]);
    }

    public function setCusto(Request $r, CrmOpportunity $opp): JsonResponse
    {
        abort_unless($this->canSeeProfit($r->user()), 403, 'Sem permissão para editar custo.');
        $v = $r->validate(['custo' => 'required|numeric|min:0']);
        $d = $opp->detalhes ?? [];
        $d['custo'] = (float) $v['custo'];
        $opp->detalhes = $d;
        $opp->save();
        return response()->json(['data' => ['id' => $opp->id, 'custo' => (float) $v['custo']]]);
    }
}
