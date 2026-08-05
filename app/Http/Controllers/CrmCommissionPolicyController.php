<?php

namespace App\Http\Controllers;

use App\Models\CrmCommissionPolicy;
use App\Models\CrmCommissionRate;
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
            'cargo' => $p->cargo, 'pipeline_id' => $p->pipeline_id,
            'min_valor' => $p->min_valor !== null ? (float) $p->min_valor : null,
            'max_valor' => $p->max_valor !== null ? (float) $p->max_valor : null,
            'min_margem' => $p->min_margem !== null ? (float) $p->min_margem : null,
            'max_margem' => $p->max_margem !== null ? (float) $p->max_margem : null,
            'percentual' => (float) $p->percentual,
        ];
    }

    public function index(): JsonResponse
    {
        $this->assertManage();
        $policies = CrmCommissionPolicy::orderBy('priority')->orderBy('id')->get();
        $pipelines = CrmPipeline::orderBy('name')->get(['id', 'name']);
        $cargos = User::where('is_crm_responsavel', true)->whereNotNull('type')->distinct()->orderBy('type')->pluck('type');
        return response()->json(['data' => [
            'policies' => $policies->map(fn ($p) => $this->present($p))->values(),
            'pipelines' => $pipelines, 'cargos' => $cargos->values(),
        ]]);
    }

    private function validated(Request $r): array
    {
        return $r->validate([
            'name' => 'required|string|max:120',
            'active' => 'boolean',
            'priority' => 'integer|min:0',
            'cargo' => 'nullable|string|max:40',
            'pipeline_id' => 'nullable|exists:crm_pipelines,id',
            'min_valor' => 'nullable|numeric|min:0',
            'max_valor' => 'nullable|numeric|min:0',
            'min_margem' => 'nullable|numeric',
            'max_margem' => 'nullable|numeric',
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
            'responsavel_id' => 'nullable|exists:users,id',
        ]);
        $cargo = $v['cargo'] ?? null;
        if (!$cargo && !empty($v['responsavel_id'])) $cargo = User::find($v['responsavel_id'])?->type;
        // fallback: % do vendedor ou padrão da empresa
        $rates = CrmCommissionRate::when($this->companyId(), fn ($q, $c) => $q->where('company_id', $c))->get();
        $default = (float) (optional($rates->firstWhere('user_id', null))->percentual ?? 0);
        $fallback = !empty($v['responsavel_id']) && ($rr = $rates->firstWhere('user_id', $v['responsavel_id']))
            ? (float) $rr->percentual : $default;

        [$pct, $regra] = CrmCommissionPolicy::resolve($cargo, $v['pipeline_id'] ?? null, (float) $v['valor'], isset($v['margem']) ? (float) $v['margem'] : null, $fallback);
        $base = (float) $v['valor'];
        return response()->json(['data' => [
            'base' => $base, 'percentual' => $pct, 'comissao' => round($base * $pct / 100, 2),
            'regra' => $regra, 'origem' => $regra ? 'política' : 'fallback (% do vendedor)',
        ]]);
    }
}
