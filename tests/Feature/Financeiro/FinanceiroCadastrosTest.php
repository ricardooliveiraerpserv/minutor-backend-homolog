<?php

namespace Tests\Feature\Financeiro;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Financeiro\FinAuditLog;
use App\Models\Financeiro\FinBankAccount;
use App\Models\Financeiro\FinCompanySetting;
use App\Models\User;
use App\Services\Financeiro\Billing\BillingGatewayException;
use App\Services\Financeiro\Billing\BillingGatewayFactory;
use App\Services\Financeiro\Billing\ChargeRequest;
use App\Services\Financeiro\Billing\ChargeResult;
use App\Services\Financeiro\Billing\FakeBillingGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Financeiro F1 — cadastros. Roda em Postgres (ilike, schema real):
 *   DB_CONNECTION=pgsql DB_DATABASE=<base com schema> php artisan test tests/Feature/Financeiro
 */
class FinanceiroCadastrosTest extends TestCase
{
    use DatabaseTransactions;

    private Company $erp;
    private Company $biz;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requer Postgres com o schema do Minutor.');
        }
        $this->erp = Company::create(['name' => 'ERP T', 'slug' => 'erp-t-' . uniqid(), 'type' => 'internal', 'status' => 'active']);
        $this->biz = Company::create(['name' => 'BIZ T', 'slug' => 'biz-t-' . uniqid(), 'type' => 'internal', 'status' => 'active']);
    }

    private function usuario(string $type = 'admin'): User
    {
        $u = User::factory()->create(['type' => $type, 'current_company_id' => $this->erp->id]);
        $u->companies()->attach([$this->erp->id => ['role' => $type], $this->biz->id => ['role' => $type]]);
        Sanctum::actingAs($u);
        return $u;
    }

    private function api(string $method, string $uri, array $data = [], ?Company $empresa = null)
    {
        return $this->withHeader('X-Company-ID', (string) ($empresa ?? $this->erp)->id)
            ->json($method, '/api/v1/financeiro' . $uri, $data);
    }

    public function test_consultor_nao_acessa(): void
    {
        $this->usuario('consultor');
        $this->api('GET', '/contas-bancarias')->assertStatus(403);
    }

    public function test_conta_bancaria_isolada_por_empresa_e_credenciais_ocultas(): void
    {
        $this->usuario('administrativo');

        $id = $this->api('POST', '/contas-bancarias', ['nome' => 'Itaú ERP', 'banco_codigo' => '341', 'gateway' => 'fake'])
            ->assertCreated()->json('id');
        $this->api('POST', '/contas-bancarias', ['nome' => 'Inter BIZ'], $this->biz)->assertCreated();

        $lista = $this->api('GET', '/contas-bancarias')->assertOk()->json();
        $this->assertSame(['Itaú ERP'], array_column($lista, 'nome'), 'só vê as contas da empresa ativa');

        // conta da outra empresa não é alcançável pela URL
        $outra = FinBankAccount::withoutGlobalScopes()->where('company_id', $this->biz->id)->value('id');
        $this->api('PUT', "/contas-bancarias/{$outra}", ['nome' => 'x'])->assertNotFound();

        $resp = $this->api('PUT', "/contas-bancarias/{$id}/credenciais", ['credenciais' => ['client_id' => 'abc', 'client_secret' => 'segredo']])
            ->assertOk();
        $this->assertTrue($resp->json('tem_credenciais'));
        $this->assertArrayNotHasKey('gateway_credenciais', $resp->json());
        $this->assertStringNotContainsString('segredo', $resp->getContent());

        $bruto = \DB::table('fin_bank_accounts')->where('id', $id)->value('gateway_credenciais');
        $this->assertStringNotContainsString('segredo', $bruto, 'gravado cifrado');
        $this->assertSame('segredo', FinBankAccount::withoutGlobalScopes()->find($id)->gateway_credenciais['client_secret']);

        $log = FinAuditLog::where('alvo_id', $id)->latest('id')->first();
        $this->assertSame('gateway.credenciais.gravadas', $log->acao);
        $this->assertStringNotContainsString('segredo', json_encode($log->detalhes));
    }

    public function test_plano_de_contas_padrao(): void
    {
        $this->usuario();
        $contas = $this->api('POST', '/plano-contas/padrao')->assertCreated()->json();
        $this->assertCount(count(\App\Models\Financeiro\FinChartAccount::PADRAO), $contas);

        $porCodigo = collect($contas)->keyBy('codigo');
        $this->assertSame($porCodigo['3']['id'], $porCodigo['3.01']['parent_id']);
        $this->assertFalse($porCodigo['3']['aceita_lancamento']);
        $this->assertSame('custo_servicos', $porCodigo['3.01']['grupo_dre']);

        $this->api('POST', '/plano-contas/padrao')->assertStatus(422);
        $this->api('POST', '/plano-contas', ['codigo' => '3.01', 'nome' => 'dup', 'natureza' => 'despesa'])->assertStatus(422);
        // mesmo código em outra empresa é permitido
        $this->api('POST', '/plano-contas', ['codigo' => '3.01', 'nome' => 'ok', 'natureza' => 'despesa'], $this->biz)->assertCreated();
    }

    public function test_favorecido_valida_documento_e_busca(): void
    {
        $this->usuario();
        $this->api('POST', '/favorecidos', ['tipo' => 'consultor', 'nome' => 'Fulano', 'documento' => '123'])->assertStatus(422);
        $this->api('POST', '/favorecidos', ['tipo' => 'cooperativa', 'nome' => 'Cooperativa Teste', 'documento' => '11.222.333/0001-81', 'pix_tipo' => 'cnpj', 'pix_chave' => '11222333000181'])
            ->assertCreated()->assertJsonPath('documento', '11222333000181');
        $this->assertCount(1, $this->api('GET', '/favorecidos?busca=coopera')->json());
        $this->assertCount(1, $this->api('GET', '/favorecidos?busca=11222')->json());
        $this->assertCount(0, $this->api('GET', '/favorecidos', [], $this->biz)->json());
    }

    public function test_cliente_fiscal_e_prontidao_para_nfse(): void
    {
        $this->usuario();
        $c = Customer::create(['name' => 'Cliente Fiscal T', 'cgc' => '11222333000181', 'active' => true]);

        $this->api('GET', "/clientes/{$c->id}/fiscal")->assertOk()->assertJsonPath('pronto_para_nfse', false);
        $this->api('PUT', "/clientes/{$c->id}/fiscal", ['email_cobranca' => 'ok@x.com; nao-email'])->assertStatus(422);

        $this->api('PUT', "/clientes/{$c->id}/fiscal", [
            'cep' => '12940-000', 'logradouro' => 'Rua A', 'numero' => '10', 'cidade' => 'Atibaia',
            'uf' => 'sp', 'codigo_ibge' => '3504107', 'email_cobranca' => 'fin@cliente.com',
        ])->assertOk()->assertJsonPath('pronto_para_nfse', true)->assertJsonPath('fiscal.uf', 'SP');
    }

    public function test_certificado_a1_cifrado_e_producao_exige_certificado(): void
    {
        $this->usuario();
        $this->api('PUT', '/empresa', ['nfse_ambiente' => 'producao'])->assertStatus(422);

        $pfx = $this->gerarPfx('EMPRESA TESTE LTDA:11222333000181', 'senha-teste');
        $arquivo = UploadedFile::fake()->createWithContent('cert.pfx', $pfx);

        $this->withHeader('X-Company-ID', (string) $this->erp->id)
            ->post('/api/v1/financeiro/empresa/certificado', ['arquivo' => $arquivo, 'senha' => 'errada'], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $arquivo = UploadedFile::fake()->createWithContent('cert.pfx', $pfx);
        $resp = $this->withHeader('X-Company-ID', (string) $this->erp->id)
            ->post('/api/v1/financeiro/empresa/certificado', ['arquivo' => $arquivo, 'senha' => 'senha-teste'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('tem_certificado', true)
            ->assertJsonPath('certificado_cnpj', '11222333000181')
            ->assertJsonPath('certificado_titular', 'EMPRESA TESTE LTDA');
        $this->assertStringNotContainsString('senha-teste', $resp->getContent());
        $this->assertArrayNotHasKey('certificado_pfx', $resp->json());

        $bruto = \DB::table('fin_company_settings')->where('company_id', $this->erp->id)->value('certificado_senha');
        $this->assertNotSame('senha-teste', $bruto);

        $this->api('PUT', '/empresa', ['nfse_ambiente' => 'producao', 'aliquota_iss' => 2])->assertOk()->assertJsonPath('nfse_ambiente', 'producao');
        $this->api('DELETE', '/empresa/certificado')->assertStatus(422); // não remove em produção
        $this->assertTrue(FinAuditLog::where('company_id', $this->erp->id)->where('acao', 'certificado.enviado')->exists());
        $this->assertSame('homologacao', FinCompanySetting::forCompany($this->biz->id)->nfse_ambiente, 'outra empresa intocada');
    }

    public function test_gateway_simulado_idempotente_e_baixa(): void
    {
        $gw = app(FakeBillingGateway::class);
        $req = new ChargeRequest('titulo-123', '7292.14', now()->addDays(10), 'Cliente', '11222333000181');

        $a = $gw->registrar($req);
        $b = $gw->registrar($req);
        $this->assertSame($a->codigoSolicitacao, $b->codigoSolicitacao);
        $this->assertSame(ChargeResult::REGISTRADA, $a->situacao);
        $this->assertTrue($a->simulado);
        $this->assertNotEmpty($a->linhaDigitavel);

        $pago = $gw->simularPagamento($a->codigoSolicitacao);
        $this->assertSame(ChargeResult::PAGA, $pago->situacao);
        $this->assertSame('7292.14', $pago->valorPago);

        $this->expectException(\InvalidArgumentException::class);
        new ChargeRequest('x', '0', now(), 'C', '1');
    }

    public function test_factory_recusa_conta_sem_gateway_e_inter_sem_credencial(): void
    {
        $f = app(BillingGatewayFactory::class);
        $semGw = new FinBankAccount(['nome' => 'Caixa livre', 'gateway' => 'none']);
        try {
            $f->paraConta($semGw);
            $this->fail('deveria recusar');
        } catch (BillingGatewayException $e) {
            $this->assertStringContainsString('não emite cobrança', $e->getMessage());
        }

        $inter = $f->paraConta(new FinBankAccount(['nome' => 'Inter', 'gateway' => 'inter']));
        $this->expectException(BillingGatewayException::class);
        $inter->registrar(new ChargeRequest('t-1', '10.00', now(), 'C', '11222333000181'));
    }

    private function gerarPfx(string $cn, string $senha): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $cn], $key);
        $cert = openssl_csr_sign($csr, null, $key, 365);
        openssl_pkcs12_export($cert, $out, $key, $senha);
        return $out;
    }
}
