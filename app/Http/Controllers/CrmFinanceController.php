<?php

namespace App\Http\Controllers;

use App\Models\CrmOpportunity;
use App\Models\CrmPipelineStage;
use App\Models\CrmGoal;
use App\Models\CrmCommissionRate;
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
        return $rows; // team/assigned/all → aberto (equipe não materializada)
    }

    /** Pode editar (definir metas/percentuais): admin, administrativo, policy.manage ou escopo team/all. */
    private function canEditScope(?User $u, string $key): bool
    {
        if (!$u) return false;
        if ($u->isAdmin() || $u->type === 'administrativo' || $this->resolver->can($u, 'crm', 'policy.manage')) return true;
        return in_array($this->resolver->scope($u, 'crm', $key, 'all'), ['team', 'all'], true);
    }

    // ── METAS ────────────────────────────────────────────────────────────────
    public function metas(Request $r): JsonResponse
    {
        $u = $r->user();
        if ($u && !$u->isAdmin() && $this->resolver->scope($u, 'crm', 'goals.view', 'all') === 'none')
            abort(403, 'Seu perfil não permite ver metas.');
        $comp = $this->comp($r);
        $resp = $this->applyScope($this->responsaveis(), 'goals.view', $u);
        $real = $this->realizadoPorResp($comp);
        $goals = CrmGoal::where('competencia', $comp)->get()->keyBy('user_id');
        $rows = $resp->map(function ($x) use ($real, $goals) {
            $meta = (float) ($goals[$x->id]->valor_meta ?? 0);
            $realizado = (float) ($real[$x->id]->total ?? 0);
            return [
                'user_id' => $x->id, 'name' => $x->name,
                'meta' => $meta, 'realizado' => $realizado,
                'qtd' => (int) ($real[$x->id]->qtd ?? 0),
                'pct' => $meta > 0 ? round($realizado / $meta * 100, 1) : null,
            ];
        })->values();
        return response()->json(['data' => [
            'competencia' => $comp, 'can_edit' => $this->canEditScope($u, 'goals.view'),
            'total_meta' => (float) $rows->sum('meta'), 'total_realizado' => (float) $rows->sum('realizado'),
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
        ]);
        $g = CrmGoal::updateOrCreate(
            ['company_id' => $this->companyId(), 'user_id' => $v['user_id'], 'competencia' => $v['competencia']],
            ['valor_meta' => $v['valor_meta']]
        );
        return response()->json(['data' => $g]);
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
        $ids = $resp->pluck('id');
        $cid = $this->companyId();

        $goals = CrmGoal::where('competencia', $comp)->when($cid, fn ($q, $c) => $q->where('company_id', $c))->get()->keyBy('user_id');
        $goalsPrev = CrmGoal::where('competencia', $prev->format('Y-m'))->when($cid, fn ($q, $c) => $q->where('company_id', $c))->get()->keyBy('user_id');

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
        $cid = $this->companyId();
        $prev = CrmGoal::where('competencia', $from)->when($cid, fn ($q, $c) => $q->where('company_id', $c))->get();
        $n = 0;
        foreach ($prev as $g) {
            CrmGoal::firstOrCreate(
                ['company_id' => $cid, 'user_id' => $g->user_id, 'competencia' => $to],
                ['valor_meta' => $g->valor_meta]
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

        return response()->json(['data' => [
            'competencia' => $comp, 'can_edit' => $this->canEditScope($u, 'commission.view'),
            'percentual_padrao' => $default, 'has_payment_tracking' => false,
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
