<?php

namespace App\Http\Controllers\Financeiro;

use App\Models\Contract;
use App\Models\Financeiro\FinBankAccount;
use App\Models\Financeiro\FinCompanySetting;
use App\Models\Financeiro\FinContractBilling;
use App\Models\Financeiro\FinInstallment;
use App\Services\Financeiro\ParcelaGenerator;
use App\Services\Financeiro\RetencoesCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** F2 — aba Faturamento do contrato + parcelas da competência. */
class ParcelaController extends FinanceiroController
{
    // ─── Aba Faturamento do contrato ──────────────────────────────────────────

    public function contrato(Request $request, int $contract, ParcelaGenerator $gen): JsonResponse
    {
        $this->autorizar($request);
        $c = $this->contratoDaEmpresa($request, $contract);
        $cfg = FinContractBilling::paraContrato($c);

        return response()->json([
            'contrato' => [
                'id' => $c->id, 'customer' => $c->customer?->only(['id', 'name', 'company_name']),
                'project' => $c->project?->only(['id', 'name', 'code']), 'tipo' => $c->contractType?->only(['code', 'name']),
                'status' => $c->status, 'valor_projeto' => $c->valor_projeto, 'valor_hora' => $c->valor_hora,
                'horas_contratadas' => $c->horas_contratadas,
            ],
            'config' => $cfg,
            'config_salva' => $cfg->exists,
            'regra_sugerida' => FinContractBilling::regraSugerida($c),
            'parcelas' => FinInstallment::withoutGlobalScopes()->where('contract_id', $c->id)
                ->orderByDesc('competencia')->orderBy('origem')->limit(60)->get(),
            'previsao' => $gen->simularContrato($c, now()->format('Y-m'), 12),
        ]);
    }

    public function salvarContrato(Request $request, int $contract): JsonResponse
    {
        $this->autorizar($request);
        $c = $this->contratoDaEmpresa($request, $contract);
        $empresa = (int) ($c->company_id ?: $c->project?->company_id);
        $data = $request->validate([
            'ativo' => 'sometimes|boolean',
            'regra_recorrente' => ['sometimes', Rule::in(FinContractBilling::REGRAS)],
            'valor_fixo' => 'nullable|numeric|min:0',
            'periodicidade' => ['sometimes', Rule::in(array_keys(FinContractBilling::PERIODICIDADES))],
            'inicio_competencia' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'fim_competencia' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'documento' => ['sometimes', Rule::in(FinContractBilling::DOCUMENTOS)],
            'codigo_tributacao_nacional' => 'nullable|string|max:20',
            'discriminacao' => 'nullable|string|max:2000',
            'dia_faturamento' => 'sometimes|integer|min:1|max:28',
            'vencimento_dias' => 'sometimes|integer|min:0|max:120',
            'bank_account_id' => ['nullable', 'integer', Rule::exists('fin_bank_accounts', 'id')->where('company_id', $empresa)],
            'chart_account_id' => ['nullable', 'integer', Rule::exists('fin_chart_accounts', 'id')->where('company_id', $empresa)],
            'cost_center_id' => 'nullable|integer|exists:cost_centers,id',
            'reter_federais' => 'sometimes|boolean',
            'aliquota_irrf' => 'nullable|numeric|min:0|max:100',
            'aliquota_csll' => 'nullable|numeric|min:0|max:100',
            'aliquota_pis' => 'nullable|numeric|min:0|max:100',
            'aliquota_cofins' => 'nullable|numeric|min:0|max:100',
            'aliquota_iss' => 'nullable|numeric|min:0|max:100',
            'iss_retido' => 'sometimes|boolean',
            'observacoes' => 'nullable|string|max:2000',
        ]);
        if (($data['regra_recorrente'] ?? null) === 'valor_fixo') {
            abort_if(empty($data['valor_fixo']), 422, 'Informe o valor fixo da parcela.');
        }
        if (! empty($data['inicio_competencia']) && ! empty($data['fim_competencia'])) {
            abort_if($data['fim_competencia'] < $data['inicio_competencia'], 422, 'O fim não pode ser antes do início.');
        }

        $cfg = FinContractBilling::firstOrNew(['contract_id' => $c->id]);
        $cfg->fill($data + ['company_id' => $empresa, 'updated_by' => $request->user()->id]);
        if (! $cfg->exists && ! array_key_exists('regra_recorrente', $data)) {
            $cfg->regra_recorrente = FinContractBilling::regraSugerida($c);
        }
        $cfg->save();

        return response()->json($cfg->fresh());
    }

    // ─── Parcelas ────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $this->autorizar($request);
        $request->validate(['competencia' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $q = $this->daEmpresa(FinInstallment::class, $request)
            ->with(['customer:id,name,company_name,cgc', 'contract:id,project_id', 'project:id,name,code'])
            ->orderBy('customer_id')->orderBy('origem');
        if ($ym = $request->query('competencia')) $q->where('competencia', $ym);
        if ($s = $request->query('status')) $q->where('status', $s);
        if ($o = $request->query('origem')) $q->where('origem', $o);
        if ($cid = $request->query('customer_id')) $q->where('customer_id', (int) $cid);

        $itens = $q->get();
        $ativas = $itens->where('status', '!=', 'cancelada');
        return response()->json([
            'itens' => $itens,
            'totais' => [
                'quantidade' => $ativas->count(),
                'bruto' => round($ativas->sum(fn ($i) => (float) $i->valor_bruto), 2),
                'desconto' => round($ativas->sum(fn ($i) => (float) $i->desconto), 2),
                'retencoes' => round($ativas->sum(fn ($i) => $i->total_retencoes), 2),
                'liquido' => round($ativas->sum(fn ($i) => (float) $i->valor_liquido), 2),
                'por_status' => $itens->groupBy('status')->map->count(),
            ],
        ]);
    }

    public function gerar(Request $request, ParcelaGenerator $gen): JsonResponse
    {
        $this->autorizar($request);
        $data = $request->validate(['competencia' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        return response()->json($gen->gerar($this->empresaId($request), $data['competencia'], $request->user()->id));
    }

    /** Parcela avulsa (ex.: entrega de contrato fechado, ajuste). */
    public function store(Request $request, RetencoesCalculator $calc): JsonResponse
    {
        $this->autorizar($request);
        $empresa = $this->empresaId($request);
        $data = $request->validate([
            'contract_id' => 'required|integer',
            'competencia' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'descricao' => 'required|string|max:255',
            'valor_bruto' => 'required|numeric|min:0.01',
            'desconto' => 'nullable|numeric|min:0',
            'documento' => ['required', Rule::in(FinContractBilling::DOCUMENTOS)],
            'vencimento' => 'required|date',
        ]);
        $c = $this->contratoDaEmpresa($request, (int) $data['contract_id']);
        $cfg = FinContractBilling::paraContrato($c);
        $ret = $calc->calcular((float) $data['valor_bruto'], (float) ($data['desconto'] ?? 0), $data['documento'], $cfg,
            FinCompanySetting::where('company_id', $empresa)->first());

        $p = FinInstallment::create([
            'company_id' => $empresa, 'contract_id' => $c->id, 'customer_id' => $c->customer_id, 'project_id' => $c->project_id,
            'competencia' => $data['competencia'], 'origem' => 'avulsa', 'origem_ref' => 'av:' . uniqid(),
            'documento' => $data['documento'], 'descricao' => $data['descricao'], 'valor_bruto' => $data['valor_bruto'],
            'desconto' => $data['desconto'] ?? 0, 'vencimento' => $data['vencimento'], 'status' => 'pronta',
            'detalhes' => ['lancada_por' => $request->user()->name], 'created_by' => $request->user()->id,
        ] + $ret);

        return response()->json($p, 201);
    }

    /** Ajuste manual de vencimento/descrição (e valor, só em avulsa). */
    public function update(Request $request, int $id, RetencoesCalculator $calc): JsonResponse
    {
        $this->autorizar($request);
        $p = $this->daEmpresa(FinInstallment::class, $request)->findOrFail($id);
        abort_unless($p->editavel(), 422, 'Parcela processada ou cancelada não pode ser alterada.');
        $data = $request->validate([
            'descricao' => 'sometimes|string|max:255',
            'vencimento' => 'sometimes|date',
            'valor_bruto' => 'sometimes|numeric|min:0.01',
            'desconto' => 'sometimes|numeric|min:0',
        ]);
        if ((isset($data['valor_bruto']) || isset($data['desconto'])) && $p->origem !== 'avulsa') {
            abort(422, 'O valor desta parcela vem da origem (contrato/fechamento). Ajuste lá ou cancele e lance uma avulsa.');
        }
        $p->fill($data);
        if (isset($data['valor_bruto']) || isset($data['desconto'])) {
            $cfg = $p->contract ? FinContractBilling::paraContrato($p->contract) : null;
            $p->fill($calc->calcular((float) $p->valor_bruto, (float) $p->desconto, $p->documento, $cfg,
                FinCompanySetting::where('company_id', $p->company_id)->first()));
        }
        $p->detalhes = ($p->detalhes ?? []) + ['editado_manual' => true];
        $p->updated_by = $request->user()->id;
        $p->save();
        return response()->json($p->fresh());
    }

    public function cancelar(Request $request, int $id): JsonResponse
    {
        $this->autorizar($request);
        $p = $this->daEmpresa(FinInstallment::class, $request)->findOrFail($id);
        abort_unless($p->editavel(), 422, 'Só parcelas previstas ou prontas podem ser canceladas.');
        $data = $request->validate(['motivo' => 'required|string|max:500']);
        $p->update(['status' => 'cancelada', 'motivo_cancelamento' => $data['motivo'], 'updated_by' => $request->user()->id]);
        return response()->json($p->fresh());
    }

    /** Contas bancárias da empresa do contrato (para o seletor da aba). */
    public function opcoes(Request $request): JsonResponse
    {
        $this->autorizar($request);
        return response()->json([
            'contas' => $this->daEmpresa(FinBankAccount::class, $request)->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'gateway']),
            'empresa' => FinCompanySetting::forCompany($this->empresaId($request))->only([
                'aliquota_irrf', 'aliquota_csll', 'aliquota_pis', 'aliquota_cofins', 'aliquota_iss', 'codigo_tributacao_nacional',
            ]),
        ]);
    }

    private function contratoDaEmpresa(Request $request, int $id): Contract
    {
        $c = Contract::withoutGlobalScopes()->with(['customer', 'project', 'contractType'])->findOrFail($id);
        // contratos antigos sem company_id herdam a empresa do projeto
        abort_unless((int) ($c->company_id ?: $c->project?->company_id) === $this->empresaId($request), 404, 'Contrato não pertence à empresa ativa.');
        return $c;
    }
}
