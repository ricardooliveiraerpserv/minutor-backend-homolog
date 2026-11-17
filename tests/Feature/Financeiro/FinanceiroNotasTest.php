<?php

namespace Tests\Feature\Financeiro;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Customer;
use App\Models\Financeiro\FinCompanySetting;
use App\Models\Financeiro\FinInstallment;
use App\Models\Financeiro\FinInvoice;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Financeiro F3 — emissão de Nota de Débito e NFS-e (emissor simulado). Postgres com schema. */
class FinanceiroNotasTest extends TestCase
{
    use DatabaseTransactions;

    private Company $erp;
    private User $u;
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requer Postgres com o schema do Minutor.');
        }
        config(['financeiro.nfse_emissor' => 'simulado', 'financeiro.nfse_permitir_producao' => false]);
        $this->erp = Company::create(['name' => 'ERP N', 'slug' => 'erp-n-' . uniqid(), 'type' => 'internal', 'status' => 'active', 'cnpj' => '11222333000181']);
        FinCompanySetting::forCompany($this->erp->id)->fill([
            'razao_social' => 'ERP N LTDA', 'inscricao_municipal' => '45481', 'codigo_ibge' => '3504107', 'dps_serie' => '900',
            'dps_proximo_numero' => 50, 'aliquota_irrf' => 1.5, 'aliquota_csll' => 1, 'aliquota_pis' => 0.65, 'aliquota_cofins' => 3,
        ])->save();
        $this->u = User::factory()->create(['type' => 'administrativo', 'current_company_id' => $this->erp->id]);
        $this->u->companies()->attach([$this->erp->id => ['role' => 'administrativo']]);
        Sanctum::actingAs($this->u);
        $this->h = ['X-Company-ID' => (string) $this->erp->id];
    }

    private function parcela(string $documento = 'nfse', bool $clienteCompleto = true, string $status = 'pronta'): FinInstallment
    {
        $cnpj = str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT);
        $cust = Customer::factory()->create($clienteCompleto ? [
            'cgc' => $cnpj, 'cep' => '12940-000', 'logradouro' => 'Rua A', 'numero' => '10',
            'cidade' => 'Atibaia', 'uf' => 'SP', 'codigo_ibge' => '3504107',
        ] : ['cgc' => $cnpj]);
        $ct = ContractType::where('code', 'on_demand')->first() ?? ContractType::factory()->create(['code' => 'on_demand', 'name' => 'On Demand']);
        $p = Project::factory()->create(['customer_id' => $cust->id, 'contract_type_id' => $ct->id, 'company_id' => $this->erp->id]);
        $c = Contract::forceCreate(['customer_id' => $cust->id, 'status' => 'ativo', 'categoria' => 'sustentacao', 'contract_type_id' => $ct->id,
            'project_id' => $p->id, 'company_id' => $this->erp->id, 'created_by_id' => $this->u->id]);
        return FinInstallment::create([
            'company_id' => $this->erp->id, 'contract_id' => $c->id, 'customer_id' => $cust->id, 'project_id' => $p->id,
            'competencia' => '2026-10', 'origem' => 'on_demand', 'origem_ref' => 'p:' . $p->id, 'documento' => $documento,
            'descricao' => 'On Demand 10/2026 — 42 h', 'horas' => 42, 'valor_bruto' => 7770, 'desconto' => 0,
            'ret_irrf' => $documento === 'nfse' ? 116.55 : 0, 'ret_csll' => $documento === 'nfse' ? 77.70 : 0,
            'ret_pis' => $documento === 'nfse' ? 50.51 : 0, 'ret_cofins' => $documento === 'nfse' ? 233.10 : 0,
            'ret_iss' => 0, 'valor_iss' => 0, 'valor_liquido' => $documento === 'nfse' ? 7292.14 : 7770,
            'vencimento' => '2026-11-11', 'status' => $status, 'detalhes' => ['despesas' => [['data' => '2026-10-03', 'descricao' => 'Hotel', 'valor' => 7770]]],
        ]);
    }

    public function test_nota_de_debito_numera_e_processa_parcela(): void
    {
        $a = $this->parcela('nota_debito');
        $b = $this->parcela('nota_debito');
        $this->withHeaders($this->h)->postJson("/api/v1/financeiro/parcelas/{$a->id}/emitir")->assertCreated()
            ->assertJsonPath('tipo', 'nota_debito')->assertJsonPath('numero', '1')->assertJsonPath('status', 'autorizada');
        $this->withHeaders($this->h)->postJson("/api/v1/financeiro/parcelas/{$b->id}/emitir")->assertCreated()->assertJsonPath('numero', '2');
        $this->assertSame('processada', $a->fresh()->status);

        // não emite de novo
        $this->withHeaders($this->h)->postJson("/api/v1/financeiro/parcelas/{$a->id}/emitir")->assertStatus(422);

        $nota = FinInvoice::where('installment_id', $a->id)->first();
        $pdf = $this->withHeaders($this->h)->get("/api/v1/financeiro/notas/{$nota->id}/pdf");
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));

        // cancelar volta a parcela para pronta
        $this->withHeaders($this->h)->postJson("/api/v1/financeiro/notas/{$nota->id}/cancelar", ['motivo' => 'curto'])->assertStatus(422);
        $this->withHeaders($this->h)->postJson("/api/v1/financeiro/notas/{$nota->id}/cancelar", ['motivo' => 'Emitida com valor errado'])
            ->assertOk()->assertJsonPath('status', 'cancelada');
        $this->assertSame('pronta', $a->fresh()->status);
    }

    public function test_nfse_simulada_reserva_dps_e_valida_dados_fiscais(): void
    {
        $incompleta = $this->parcela('nfse', clienteCompleto: false);
        $this->withHeaders($this->h)->postJson("/api/v1/financeiro/parcelas/{$incompleta->id}/emitir")
            ->assertStatus(422)->assertJsonFragment(['message' => 'Dados fiscais incompletos: logradouro do cliente, numero do cliente, cidade do cliente, uf do cliente, codigo_ibge do cliente, cep do cliente.']);
        $this->assertSame(50, FinCompanySetting::forCompany($this->erp->id)->dps_proximo_numero, 'validação falhou antes de reservar nº');

        $p = $this->parcela('nfse');
        $r = $this->withHeaders($this->h)->postJson("/api/v1/financeiro/parcelas/{$p->id}/emitir")->assertCreated();
        $r->assertJsonPath('emissor', 'simulado')->assertJsonPath('numero_dps', 50)->assertJsonPath('serie', '900')
            ->assertJsonPath('numero', 'SIM-50')->assertJsonPath('valor_liquido', '7292.14');
        $this->assertStringContainsString('On Demand 10/2026', $r->json('discriminacao'));
        $this->assertSame(51, FinCompanySetting::forCompany($this->erp->id)->dps_proximo_numero);
        $this->assertSame('processada', $p->fresh()->status);

        $lista = $this->withHeaders($this->h)->getJson('/api/v1/financeiro/notas?competencia=2026-10')->assertOk()->json();
        $this->assertCount(1, $lista);
        $this->assertArrayNotHasKey('xml_dps', $lista[0]);
    }

    public function test_producao_bloqueada_e_lote_independente(): void
    {
        FinCompanySetting::where('company_id', $this->erp->id)->update(['nfse_ambiente' => 'producao']);
        $p = $this->parcela('nfse');
        $this->withHeaders($this->h)->postJson("/api/v1/financeiro/parcelas/{$p->id}/emitir")
            ->assertStatus(422)->assertJsonPath('message', 'Emissão de NFS-e em PRODUÇÃO está bloqueada neste ambiente (FIN_NFSE_PERMITIR_PRODUCAO).');
        FinCompanySetting::where('company_id', $this->erp->id)->update(['nfse_ambiente' => 'homologacao']);

        $ok = $this->parcela('nota_debito');
        $prevista = $this->parcela('nfse', status: 'prevista');
        $r = $this->withHeaders($this->h)->postJson('/api/v1/financeiro/parcelas/emitir-lote', ['ids' => [$ok->id, $prevista->id, $p->id]])->assertOk();
        $this->assertSame(2, $r->json('autorizadas'));
        $this->assertCount(1, $r->json('falhas'));
        $this->assertSame($prevista->id, $r->json('falhas.0.parcela_id'));
    }

    public function test_consultor_nao_emite(): void
    {
        $p = $this->parcela('nota_debito');
        $c = User::factory()->create(['type' => 'consultor', 'current_company_id' => $this->erp->id]);
        $c->companies()->attach([$this->erp->id => ['role' => 'consultor']]);
        Sanctum::actingAs($c);
        $this->withHeaders($this->h)->postJson("/api/v1/financeiro/parcelas/{$p->id}/emitir")->assertStatus(403);
    }
}
