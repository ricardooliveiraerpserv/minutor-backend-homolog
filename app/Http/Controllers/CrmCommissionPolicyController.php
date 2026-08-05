<?php

namespace App\Http\Controllers;

use App\Models\CrmCommissionPolicy;
use App\Models\CrmCommissionRate;
use App\Models\CrmCommissionSetting;
use App\Models\CrmCommissionRateHistory;
use App\Models\CrmPipeline;
use App\Models\User;
use App\Services\PolicyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRM — Políticas de Comissão (regras condicionais de %) + simulador.
 * Gestão restrita a admin/administrativo/policy.manage.
 */
class CrmCommissionPolicyController extends Controller
{
    use \App\Http\Traits\FiltersByActiveCompany;

    public function __construct(private PolicyResolver $resolver) {}

    private function companyId(): ?int { return $this->activeCompanyId(); }

    private function assertManage(): void
    {
        $u = auth()->user();
        abort_unless($u && ($u->isAdmin() || $u->type === 'administrativo' || $this->resolver->can($u, 'crm', 'policy.manage')), 403, 'Sem acesso às políticas de comissão.');
    }

    private function present(CrmCommissionPolicy $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'active' => (bool) $p->active, 'priority' => (int) $p->priority,
            'cargo' => $p->cargo, 'pipeline_id' => $p->pipeline_id, 'tipo_negocio' => $p->tipo_negocio,
            'min_valor' => $p->min_valor !== null ? (float) $p->min_valor : null,
            'max_valor' => $p->max_valor !== null ? (float) $p->max_valor : null,
            'min_margem' => $p->min_margem !== null ? (float) $p->min_margem : null,
            'max_margem' => $p->max_margem !== null ? (float) $p->max_margem : null,
            'min_atingimento' => $p->min_atingimento !== null ? (float) $p->min_atingimento : null,
            'max_atingimento' => $p->max_atingimento !== null ? (float) $p->max_atingimento : null,
            'percentual' => (float) $p->percentual,
        ];
    }

    /** Singleton da Política Padrão da empresa. */
    private function settings(): CrmCommissionSetting
    {
        return CrmCommissionSetting::firstOrCreate(['company_id' => $this->companyId()], []);
    }

    public function index(): JsonResponse
    {
        $this->assertManage();
        $policies = CrmCommissionPolicy::orderBy('priority')->orderBy('id')->get();
        $pipelines = CrmPipeline::orderBy('name')->get(['id', 'name']);
        $cargos = User::where('is_crm_responsavel', true)->whereNotNull('type')->distinct()->orderBy('type')->pluck('type');
        $s = $this->settings();
        $resp = User::where('is_crm_responsavel', true)->orderBy('name')->get(['id', 'name', 'type']);
        $rates = CrmCommissionRate::whereNotNull('user_id')->get()->keyBy('user_id');
        $exceptions = $resp->map(fn ($u) => [
            'user_id' => $u->id, 'name' => $u->name, 'cargo' => $u->type,
            'percentual' => $rates->has($u->id) ? (float) $rates[$u->id]->percentual : null,
            'vigencia_inicio' => $rates[$u->id]->vigencia_inicio ?? null,
            'vigencia_fim' => $rates[$u->id]->vigencia_fim ?? null,
            'motivo' => $rates[$u->id]->motivo ?? null,
        ])->values();
        return response()->json(['data' => [
            'policies' => $policies->map(fn ($p) => $this->present($p))->values(),
            'pipelines' => $pipelines, 'cargos' => $cargos->values(),
            'settings' => [
                'percentual_padrao' => (float) $s->percentual_padrao, 'base_calculo' => $s->base_calculo,
                'pagamento' => $s->pagamento, 'forma_calculo' => $s->forma_calculo,
            ],
            'exceptions' => $exceptions,
        ]]);
    }

    /** Política Padrão da empresa. */
    public function setSettings(Request $r): JsonResponse
    {
        $this->assertManage();
        $v = $r->validate([
            'percentual_padrao' => 'required|numeric|min:0|max:100',
            'base_calculo' => 'required|in:valor,receita_liquida,margem',
            'pagamento' => 'required|in:ganho,faturado,recebido',
            'forma_calculo' => 'required|in:fixo,progressivo,faixa,margem',
            'motivo' => 'nullable|string|max:200',
        ]);
        $s = $this->settings();
        $anterior = (float) $s->percentual_padrao;
        $s->update([
            'percentual_padrao' => $v['percentual_padrao'], 'base_calculo' => $v['base_calculo'],
            'pagamento' => $v['pagamento'], 'forma_calculo' => $v['forma_calculo'],
        ]);
        CrmCommissionRateHistory::create([
            'user_id' => null, 'valor_anterior' => $anterior, 'valor_novo' => (float) $v['percentual_padrao'],
            'campo' => 'politica_padrao', 'motivo' => $v['motivo'] ?? null,
            'changed_by_id' => auth()->id(), 'ip' => $r->ip(), 'created_at' => now(),
        ]);
        return response()->json(['data' => ['ok' => true]]);
    }

    /** Exceção de comissão por vendedor (com motivo → auditoria). */
    public function setException(Request $r): JsonResponse
    {
        $this->assertManage();
        $v = $r->validate([
            'user_id' => 'required|exists:users,id',
            'percentual' => 'required|numeric|min:0|max:100',
            'vigencia_inicio' => 'nullable|date',
            'vigencia_fim' => 'nullable|date',
            'motivo' => 'required|string|max:200',
        ]);
        $existing = CrmCommissionRate::where('company_id', $this->companyId())->where('user_id', $v['user_id'])->first();
        $anterior = $existing ? (float) $existing->percentual : null;
        $rate = CrmCommissionRate::updateOrCreate(
            ['company_id' => $this->companyId(), 'user_id' => $v['user_id']],
            ['percentual' => $v['percentual'], 'vigencia_inicio' => $v['vigencia_inicio'] ?? null,
             'vigencia_fim' => $v['vigencia_fim'] ?? null, 'motivo' => $v['motivo']]
        );
        CrmCommissionRateHistory::create([
            'user_id' => $v['user_id'], 'valor_anterior' => $anterior, 'valor_novo' => (float) $v['percentual'],
            'campo' => 'percentual', 'motivo' => $v['motivo'], 'changed_by_id' => auth()->id(),
            'ip' => $r->ip(), 'created_at' => now(),
        ]);
        return response()->json(['data' => ['id' => $rate->id]]);
    }

    /** Remove a exceção (volta a usar a Política Padrão). */
    public function deleteException(Request $r, User $user): JsonResponse
    {
        $this->assertManage();
        $rate = CrmCommissionRate::where('company_id', $this->companyId())->where('user_id', $user->id)->first();
        if ($rate) {
            CrmCommissionRateHistory::create([
                'user_id' => $user->id, 'valor_anterior' => (float) $rate->percentual, 'valor_novo' => null,
                'campo' => 'percentual', 'motivo' => 'Removida (volta à política padrão)',
                'changed_by_id' => auth()->id(), 'ip' => $r->ip(), 'created_at' => now(),
            ]);
            $rate->delete();
        }
        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Auditoria de alterações de política/exceção. */
    public function rateHistory(): JsonResponse
    {
        $this->assertManage();
        $names = User::whereNotNull('name')->pluck('name', 'id');
        $rows = CrmCommissionRateHistory::with('changedBy:id,name')->orderByDesc('created_at')->limit(200)->get()->map(fn ($h) => [
            'id' => $h->id, 'alvo' => $h->user_id ? ($names[$h->user_id] ?? '—') : 'Política padrão',
            'campo' => $h->campo, 'valor_anterior' => $h->valor_anterior !== null ? (float) $h->valor_anterior : null,
            'valor_novo' => $h->valor_novo !== null ? (float) $h->valor_novo : null,
            'motivo' => $h->motivo, 'por' => $h->changedBy?->name, 'ip' => $h->ip,
            'em' => $h->created_at?->toDateTimeString(),
        ]);
        return response()->json(['data' => $rows->values()]);
    }

    private function validated(Request $r): array
    {
        return $r->validate([
            'name' => 'required|string|max:120',
            'active' => 'boolean',
            'priority' => 'integer|min:0',
            'cargo' => 'nullable|string|max:40',
            'pipeline_id' => 'nullable|exists:crm_pipelines,id',
            'tipo_negocio' => 'nullable|string|max:40',
            'min_valor' => 'nullable|numeric|min:0',
            'max_valor' => 'nullable|numeric|min:0',
            'min_margem' => 'nullable|numeric',
            'max_margem' => 'nullable|numeric',
            'min_atingimento' => 'nullable|numeric',
            'max_atingimento' => 'nullable|numeric',
            'percentual' => 'required|numeric|min:0|max:100',
        ]);
    }

    public function store(Request $r): JsonResponse
    {
        $this->assertManage();
        $p = CrmCommissionPolicy::create($this->validated($r));
        return response()->json(['data' => $this->present($p)], 201);
    }

    public function update(Request $r, CrmCommissionPolicy $policy): JsonResponse
    {
        $this->assertManage();
        $policy->update($this->validated($r));
        return response()->json(['data' => $this->present($policy)]);
    }

    public function destroy(CrmCommissionPolicy $policy): JsonResponse
    {
        $this->assertManage();
        $policy->delete();
        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Simulador: dado um cenário, mostra qual regra vence e a comissão resultante. */
    public function simular(Request $r): JsonResponse
    {
        $this->assertManage();
        $v = $r->validate([
            'valor' => 'required|numeric|min:0',
            'margem' => 'nullable|numeric',
            'cargo' => 'nullable|string|max:40',
            'pipeline_id' => 'nullable|integer',
            'tipo_negocio' => 'nullable|string|max:40',
            'atingimento' => 'nullable|numeric',
            'responsavel_id' => 'nullable|exists:users,id',
        ]);
        $cargo = $v['cargo'] ?? null;
        if (!$cargo && !empty($v['responsavel_id'])) $cargo = User::find($v['responsavel_id'])?->type;
        // fallback: % do vendedor ou padrão da empresa
        $rates = CrmCommissionRate::when($this->companyId(), fn ($q, $c) => $q->where('company_id', $c))->get();
        $default = (float) (optional($rates->firstWhere('user_id', null))->percentual ?? 0);
        $fallback = !empty($v['responsavel_id']) && ($rr = $rates->firstWhere('user_id', $v['responsavel_id']))
            ? (float) $rr->percentual : $default;

        [$pct, $regra] = CrmCommissionPolicy::resolve([
            'cargo' => $cargo, 'pipeline_id' => $v['pipeline_id'] ?? null, 'valor' => (float) $v['valor'],
            'margem' => isset($v['margem']) ? (float) $v['margem'] : null, 'tipo' => $v['tipo_negocio'] ?? null,
            'atingimento' => isset($v['atingimento']) ? (float) $v['atingimento'] : null,
        ], $fallback);
        $base = (float) $v['valor'];
        return response()->json(['data' => [
            'base' => $base, 'percentual' => $pct, 'comissao' => round($base * $pct / 100, 2),
            'regra' => $regra, 'origem' => $regra ? 'política' : 'fallback (% do vendedor)',
        ]]);
    }
}
