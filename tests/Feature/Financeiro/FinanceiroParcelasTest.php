<?php

namespace Tests\Feature\Financeiro;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Customer;
use App\Models\ExcessHourCharge;
use App\Models\FechamentoCliente;
use App\Models\Financeiro\FinCompanySetting;
use App\Models\Financeiro\FinContractBilling;
use App\Models\Financeiro\FinInstallment;
use App\Models\Project;
use App\Models\User;
use App\Services\Financeiro\ParcelaGenerator;
use App\Services\Financeiro\RetencoesCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Financeiro F2 — parcelas. Postgres com schema (ver FinanceiroCadastrosTest). */
class FinanceiroParcelasTest extends TestCase
{
    use DatabaseTransactions;

    private Company $erp;
    private Company $biz;
    private ?User $autor = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requer Postgres com o schema do Minutor.');
        }
        $this->erp = Company::create(['name' => 'ERP P', 'slug' => 'erp-p-' . uniqid(), 'type' => 'internal', 'status' => 'active']);
        $this->biz = Company::create(['name' => 'BIZ P', 'slug' => 'biz-p-' . uniqid(), 'type' => 'internal', 'status' => 'active']);
        FinCompanySetting::forCompany($this->erp->id)->fill([
            'aliquota_irrf' => 1.5, 'aliquota_csll' => 1, 'aliquota_pis' => 0.65, 'aliquota_cofins' => 3, 'aliquota_iss' => 2,
        ])->save();
    }

    private function contrato(string $tipo = 'cloud', ?Company $empresa = null, array $proj = []): Contract
    {
        $ct = ContractType::where('code', $tipo)->first() ?? ContractType::factory()->create(['code' => $tipo, 'name' => $tipo]);
        $cust = Customer::factory()->create();
        $p = Project::factory()->create(['customer_id' => $cust->id, 'contract_type_id' => $ct->id, 'company_id' => ($empresa ?? $this->erp)->id] + $proj);
        return Contract::forceCreate([
            'customer_id' => $cust->id, 'status' => 'ativo', 'categoria' => 'sustentacao', 'contract_type_id' => $ct->id,
            'project_id' => $p->id, 'company_id' => ($empresa ?? $this->erp)->id,
            'created_by_id' => ($this->autor ??= User::factory()->create())->id,
        ])->load(['project', 'contractType']);
    }

    public function test_retencoes_e_dispensa_abaixo_de_10(): void
    {
        $calc = app(RetencoesCalculator::class);
        $emp = FinCompanySetting::forCompany($this->erp->id);

        $r = $calc->calcular(7770.00, 0, 'nfse', null, $emp);
        $this->assertSame([116.55, 77.7, 50.51, 233.1, 0.0, 155.4, 7292.14],
            [$r['ret_irrf'], $r['ret_csll'], $r['ret_pis'], $r['ret_cofins'], $r['ret_iss'], $r['valor_iss'], $r['valor_liquido']]);

        $peq = $calc->calcular(150.00, 0, 'nfse', null, $emp); // IRRF 2,25 e PCC 6,98 < 10
        $this->assertSame(0.0, $peq['ret_irrf'] + $peq['ret_pis'] + $peq['ret_cofins'] + $peq['ret_csll']);
        $this->assertSame(150.0, $peq['valor_liquido']);

        $nd = $calc->calcular(834.50, 0, 'nota_debito', null, $emp);
        $this->assertSame(834.5, $nd['valor_liquido']);

        $issRet = $calc->calcular(1000, 100, 'nfse', new FinContractBilling(['iss_retido' => true]), $emp);
        $this->assertSame(18.0, $issRet['ret_iss']); // 2% de 900
    }

    public function test_periodicidade(): void
    {
        $cfg = new FinContractBilling(['periodicidade' => 'trimestral', 'inicio_competencia' => '2026-01', 'fim_competencia' => '2026-12']);
        $this->assertTrue($cfg->competenciaCobrada('2026-04'));
        $this->assertFalse($cfg->competenciaCobrada('2026-05'));
        $this->assertFalse($cfg->competenciaCobrada('2025-12'));
        $this->assertFalse($cfg->competenciaCobrada('2027-01'));
    }

    public function test_recorrente_idempotente_e_parcela_processada_travada(): void
    {
        $c = $this->contrato('cloud');
        FinContractBilling::create(['contract_id' => $c->id, 'company_id' => $this->erp->id, 'regra_recorrente' => 'valor_fixo', 'valor_fixo' => 18000]);
        $gen = app(ParcelaGenerator::class);

        $r1 = $gen->gerar($this->erp->id, '2026-10');
        $this->assertSame(1, $r1['criadas']);
        $p = FinInstallment::withoutGlobalScopes()->where('contract_id', $c->id)->first();
        $this->assertSame('16893.00', $p->valor_liquido);  // 18000 - 6,15%
        $this->assertSame('2026-11-11', $p->vencimento->toDateString()); // emissão 01/11 + 10 dias
        $this->assertSame('pronta', $p->status);

        $this->assertSame(1, $gen->gerar($this->erp->id, '2026-10')['inalteradas']);
        $this->assertSame(0, $gen->gerar($this->biz->id, '2026-10')['criadas'], 'outra empresa não gera');

        $p->update(['status' => 'processada']);
        FinContractBilling::where('contract_id', $c->id)->update(['valor_fixo' => 20000]);
        $this->assertSame(1, $gen->gerar($this->erp->id, '2026-10')['travadas']);
        $this->assertSame('18000.00', $p->fresh()->valor_bruto);

        $futura = now()->addMonths(2)->format('Y-m');
        $gen->gerar($this->erp->id, $futura);
        $this->assertSame('prevista', FinInstallment::withoutGlobalScopes()->where('contract_id', $c->id)->where('competencia', $futura)->value('status'));
    }

    public function test_banco_de_horas_usa_horas_vendidas_vezes_valor_hora(): void
    {
        $c = $this->contrato('monthly_hours', null, ['sold_hours' => 40, 'hourly_rate' => 150]);
        FinContractBilling::create(['contract_id' => $c->id, 'company_id' => $this->erp->id, 'regra_recorrente' => 'banco_horas']);
        app(ParcelaGenerator::class)->gerar($this->erp->id, '2026-10');
        $p = FinInstallment::withoutGlobalScopes()->where('contract_id', $c->id)->first();
        $this->assertSame('6000.00', $p->valor_bruto);
        $this->assertSame('40.00', $p->horas);
    }

    public function test_on_demand_do_fechamento_com_desconto_e_excedente(): void
    {
        $c = $this->contrato('on_demand');
        FechamentoCliente::withoutGlobalScopes()->forceCreate([
            'customer_id' => $c->customer_id, 'year_month' => '2026-10', 'status' => 'closed', 'company_id' => $this->erp->id,
            'total_servicos' => 7770, 'total_despesas' => 0, 'total_geral' => 7770, 'desconto' => 770,
            'snapshot_contratos' => ['projetos' => [['projeto_id' => $c->project_id, 'projeto_codigo' => 'X', 'projeto_nome' => 'Y',
                'horas' => 42, 'horas_cobradas' => 42, 'valor_hora' => 185, 'total_receita' => 7770]]],
            'snapshot_despesas' => [],
        ]);
        ExcessHourCharge::withoutGlobalScopes()->forceCreate([
            'project_id' => $c->project_id, 'year_month' => '2026-10', 'basis' => 'monthly', 'contracted_hours' => 10,
            'consumed_hours' => 16, 'excess_hours' => 6, 'additional_hourly_rate' => 210, 'excess_value' => 1260,
            'status' => 'cobrado', 'company_id' => $this->erp->id,
        ]);

        $r = app(ParcelaGenerator::class)->gerar($this->erp->id, '2026-10');
        $this->assertSame(2, $r['criadas']);

        $od = FinInstallment::withoutGlobalScopes()->where('contract_id', $c->id)->where('origem', 'on_demand')->first();
        $this->assertSame('7770.00', $od->valor_bruto);
        $this->assertSame('770.00', $od->desconto);
        $this->assertSame('6569.50', $od->valor_liquido); // 7000 - 6,15%
        $this->assertSame('42.00', $od->horas);

        $ex = FinInstallment::withoutGlobalScopes()->where('contract_id', $c->id)->where('origem', 'excedente')->first();
        $this->assertSame('1260.00', $ex->valor_bruto);
        $this->assertSame('1182.51', $ex->valor_liquido);
    }

    public function test_liberacao_on_demand_e_despesa_sem_fechamento_encerrado(): void
    {
        $c = $this->contrato('on_demand');
        \App\Models\OnDemandBillingRelease::create(['project_id' => $c->project_id, 'year_month' => '2026-09', 'released_at' => now()]);
        \App\Models\Expense::withoutGlobalScopes()->forceCreate([
            'user_id' => ($this->autor ??= User::factory()->create())->id, 'project_id' => $c->project_id,
            'expense_category_id' => \App\Models\ExpenseCategory::factory()->create()->id, 'expense_date' => '2026-09-15',
            'description' => 'Hotel', 'amount' => 834.50, 'status' => 'approved', 'charge_client' => true,
            'expense_type' => 'reimbursement', 'payment_method' => 'pix', 'company_id' => $this->erp->id,
        ]);

        $r = app(ParcelaGenerator::class)->gerar($this->erp->id, '2026-09');
        // projeto liberado sem horas apontadas → sem parcela On Demand; despesa → 1 nota de débito
        $this->assertSame(1, $r['criadas'], json_encode($r));
        $nd = FinInstallment::withoutGlobalScopes()->where('contract_id', $c->id)->first();
        $this->assertSame('despesa', $nd->origem);
        $this->assertSame('nota_debito', $nd->documento);
        $this->assertSame('834.50', $nd->valor_liquido);
        $this->assertSame('liberacao_on_demand', $nd->detalhes['fonte']);
    }

    public function test_api_aba_faturamento_e_isolamento(): void
    {
        $u = User::factory()->create(['type' => 'admin', 'current_company_id' => $this->erp->id]);
        $u->companies()->attach([$this->erp->id => ['role' => 'admin'], $this->biz->id => ['role' => 'admin']]);
        Sanctum::actingAs($u);
        $h = ['X-Company-ID' => (string) $this->erp->id];

        $c = $this->contrato('cloud');
        $outra = $this->contrato('cloud', $this->biz);

        $this->withHeaders($h)->getJson("/api/v1/financeiro/contratos/{$c->id}/faturamento")
            ->assertOk()->assertJsonPath('regra_sugerida', 'mensalidade')->assertJsonPath('config_salva', false);
        $this->withHeaders($h)->getJson("/api/v1/financeiro/contratos/{$outra->id}/faturamento")->assertNotFound();

        $this->withHeaders($h)->putJson("/api/v1/financeiro/contratos/{$c->id}/faturamento", ['regra_recorrente' => 'valor_fixo'])->assertStatus(422);
        $this->withHeaders($h)->putJson("/api/v1/financeiro/contratos/{$c->id}/faturamento", [
            'regra_recorrente' => 'valor_fixo', 'valor_fixo' => 1000, 'vencimento_dias' => 15, 'dia_faturamento' => 5,
        ])->assertOk()->assertJsonPath('vencimento_dias', 15);

        $this->withHeaders($h)->postJson('/api/v1/financeiro/parcelas/gerar', ['competencia' => '2026-10'])->assertOk()->assertJsonPath('criadas', 1);
        $lista = $this->withHeaders($h)->getJson('/api/v1/financeiro/parcelas?competencia=2026-10')->assertOk();
        $this->assertSame(1, $lista->json('totais.quantidade'));
        $id = $lista->json('itens.0.id');
        $this->assertSame('2026-11-20', substr($lista->json('itens.0.vencimento'), 0, 10)); // 05/11 + 15

        $this->withHeaders($h)->putJson("/api/v1/financeiro/parcelas/{$id}", ['valor_bruto' => 5])->assertStatus(422);
        $this->withHeaders($h)->postJson("/api/v1/financeiro/parcelas/{$id}/cancelar", ['motivo' => 'teste'])->assertOk()->assertJsonPath('status', 'cancelada');
        $this->withHeaders($h)->postJson("/api/v1/financeiro/parcelas/{$id}/cancelar", ['motivo' => 'de novo'])->assertStatus(422);

        $this->withHeaders($h)->postJson('/api/v1/financeiro/parcelas', [
            'contract_id' => $c->id, 'competencia' => '2026-10', 'descricao' => 'Entrega fase 1', 'valor_bruto' => 5000,
            'documento' => 'nfse', 'vencimento' => '2026-11-30',
        ])->assertCreated()->assertJsonPath('origem', 'avulsa')->assertJsonPath('valor_liquido', '4692.50');
    }
}
