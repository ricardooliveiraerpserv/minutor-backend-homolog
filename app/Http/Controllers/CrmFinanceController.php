<?php

namespace App\Http\Controllers;

use App\Models\CrmOpportunity;
use App\Models\CrmGoal;
use App\Models\CrmCommissionRate;
use App\Models\User;
use App\Services\PolicyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
            ->orderBy('name')->get(['id', 'name']);
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
