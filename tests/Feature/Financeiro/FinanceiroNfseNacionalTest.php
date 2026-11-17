<?php

namespace Tests\Feature\Financeiro;

use App\Models\Company;
use App\Models\Financeiro\FinCompanySetting;
use App\Models\Financeiro\FinInstallment;
use App\Models\Financeiro\FinInvoice;
use App\Services\Financeiro\Nfse\DpsXmlBuilder;
use App\Services\Financeiro\Nfse\NacionalNfseEmissor;
use App\Services\Financeiro\Nfse\XmlSigner;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DPS v1.01 + assinatura + chamada à SEFIN (fake). Sem banco: modelos em memória.
 * Estrutura conferida contra a sequência dos XSD oficiais de produção restrita (20/10/2026).
 */
class FinanceiroNfseNacionalTest extends TestCase
{
    private function cenario(array $parcela = []): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'EMPRESA TESTE:23870826000107'], $key), null, $key, 30);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);
        openssl_pkcs12_export($cert, $pfx, $key, 'senha');

        $empresa = new FinCompanySetting([
            'razao_social' => 'ERPSERV', 'inscricao_municipal' => '45481', 'codigo_ibge' => '3504107',
            'codigo_tributacao_nacional' => '01.07.01', 'nfse_ambiente' => 'homologacao', 'regime_tributario' => 'lucro_presumido',
        ]);
        $empresa->certificado_pfx = base64_encode($pfx);
        $empresa->certificado_senha = 'senha';

        $nota = new FinInvoice([
            'tipo' => 'nfse', 'serie' => '900', 'numero_dps' => 1234, 'competencia' => '2026-10',
            'valor_servico' => 7770, 'desconto' => 0, 'valor_liquido' => 7292.14,
            'discriminacao' => "Sustentação Protheus\ncompetência 10/2026",
            'tomador' => ['company_name' => 'CLIENTE & CIA <LTDA>', 'cgc' => '11.222.333/0001-81', 'codigo_ibge' => '3550308',
                'cep' => '01310-100', 'logradouro' => 'Av. Paulista', 'numero' => '1000', 'bairro' => 'Bela Vista',
                'inscricao_municipal' => '123', 'email_cobranca' => 'x@y.com; z@w.com'],
        ]);
        $nota->setRelation('company', new Company(['cnpj' => '23.870.826/0001-07']));
        $p = new FinInstallment(array_merge(['descricao' => 'On Demand', 'ret_irrf' => 116.55, 'ret_csll' => 77.70,
            'ret_pis' => 50.51, 'ret_cofins' => 233.10, 'ret_iss' => 0], $parcela));

        return [$empresa, $nota, $p, $certPem, $keyPem];
    }

    private function filhos(\DOMElement $e): array
    {
        $o = [];
        foreach ($e->childNodes as $c) if ($c instanceof \DOMElement) $o[] = $c->localName;
        return $o;
    }

    public function test_dps_segue_a_sequencia_do_xsd_e_id_de_45(): void
    {
        [$empresa, $nota, $p] = $this->cenario();
        $doc = app(DpsXmlBuilder::class)->construir($nota, $empresa, $p, new \DateTime('2026-11-17T10:00:00-03:00'));
        $inf = $doc->getElementsByTagName('infDPS')->item(0);

        $this->assertSame('DPS3504107223870826000107' . '00900' . '000000000001234', $inf->getAttribute('Id'));
        $this->assertSame(45, strlen($inf->getAttribute('Id')));
        $this->assertMatchesRegularExpression('/^DPS[0-9]{7}(1[0-9]{14}|2[0-9A-Z]{14})[0-9]{20}$/', $inf->getAttribute('Id'));
        $this->assertSame('1.01', $doc->documentElement->getAttribute('versao'));
        $this->assertSame(DpsXmlBuilder::NS, $doc->documentElement->namespaceURI);

        $this->assertSame(['tpAmb', 'dhEmi', 'verAplic', 'serie', 'nDPS', 'dCompet', 'tpEmit', 'finNFSe', 'cLocEmi', 'prest', 'toma', 'serv', 'valores'], $this->filhos($inf));
        $this->assertSame('2', $doc->getElementsByTagName('tpAmb')->item(0)->textContent);
        $this->assertSame('2026-11-17T10:00:00-03:00', $doc->getElementsByTagName('dhEmi')->item(0)->textContent);
        $this->assertSame('2026-10-01', $doc->getElementsByTagName('dCompet')->item(0)->textContent);

        $prest = $doc->getElementsByTagName('prest')->item(0);
        $this->assertSame(['CNPJ', 'IM', 'regTrib'], $this->filhos($prest), 'emitente não leva endereço');
        $this->assertSame(['opSimpNac', 'regEspTrib'], $this->filhos($doc->getElementsByTagName('regTrib')->item(0)));

        $toma = $doc->getElementsByTagName('toma')->item(0);
        $this->assertSame(['CNPJ', 'IM', 'xNome', 'end', 'email'], $this->filhos($toma));
        $this->assertSame('11222333000181', $toma->getElementsByTagName('CNPJ')->item(0)->textContent);
        $this->assertSame(['endNac', 'xLgr', 'nro', 'xBairro'], $this->filhos($toma->getElementsByTagName('end')->item(0)));
        $this->assertSame('01310100', $toma->getElementsByTagName('CEP')->item(0)->textContent);
        $this->assertSame('x@y.com', $toma->getElementsByTagName('email')->item(0)->textContent);

        $this->assertSame(['locPrest', 'cServ'], $this->filhos($doc->getElementsByTagName('serv')->item(0)));
        $this->assertSame('010701', $doc->getElementsByTagName('cTribNac')->item(0)->textContent);
        $this->assertSame(['cTribNac', 'xDescServ'], $this->filhos($doc->getElementsByTagName('cServ')->item(0)));

        $val = $doc->getElementsByTagName('valores')->item(0);
        $this->assertSame(['vServPrest', 'trib'], $this->filhos($val));
        $this->assertSame('7770.00', $doc->getElementsByTagName('vServ')->item(0)->textContent);
        $this->assertSame(['tribMun', 'tribFed', 'totTrib'], $this->filhos($doc->getElementsByTagName('trib')->item(0)));
        $this->assertSame(['tribISSQN', 'tpRetISSQN'], $this->filhos($doc->getElementsByTagName('tribMun')->item(0)));
        $this->assertSame(['piscofins', 'vRetIRRF', 'vRetCSLL'], $this->filhos($doc->getElementsByTagName('tribFed')->item(0)));
        $this->assertSame(['CST', 'tpRetPisCofins'], $this->filhos($doc->getElementsByTagName('piscofins')->item(0)));
        $this->assertSame('3', $doc->getElementsByTagName('tpRetPisCofins')->item(0)->textContent);
        $this->assertSame('361.31', $doc->getElementsByTagName('vRetCSLL')->item(0)->textContent); // 77,70+50,51+233,10
        $this->assertSame('116.55', $doc->getElementsByTagName('vRetIRRF')->item(0)->textContent);

        // decimais no padrão TSDec15V2
        foreach (['vServ', 'vRetIRRF', 'vRetCSLL'] as $t) {
            $this->assertMatchesRegularExpression('/^(0|0\.[0-9]{2}|[1-9][0-9]{0,14}(\.[0-9]{2})?)$/', $doc->getElementsByTagName($t)->item(0)->textContent);
        }
        // caracteres especiais escapados no XML serializado
        $this->assertStringContainsString('CLIENTE &amp; CIA &lt;LTDA&gt;', $doc->saveXML());
    }

    public function test_sem_retencao_omite_tribfed_e_desconto_vira_vdescincond(): void
    {
        [$empresa, $nota, $p] = $this->cenario(['ret_irrf' => 0, 'ret_csll' => 0, 'ret_pis' => 0, 'ret_cofins' => 0]);
        $nota->desconto = 100;
        $doc = app(DpsXmlBuilder::class)->construir($nota, $empresa, $p);
        $this->assertSame(['tribMun', 'totTrib'], $this->filhos($doc->getElementsByTagName('trib')->item(0)));
        $this->assertSame(['vServPrest', 'vDescCondIncond', 'trib'], $this->filhos($doc->getElementsByTagName('valores')->item(0)));
        $this->assertSame('100.00', $doc->getElementsByTagName('vDescIncond')->item(0)->textContent);
    }

    public function test_assinatura_envelopada_valida(): void
    {
        [$empresa, $nota, $p, $cert, $key] = $this->cenario();
        $signer = app(XmlSigner::class);
        foreach (['sha1', 'sha256'] as $alg) {
            $doc = app(DpsXmlBuilder::class)->construir($nota, $empresa, $p);
            $signer->assinar($doc, 'infDPS', $cert, $key, $alg);
            $this->assertSame(['infDPS', 'Signature'], $this->filhos($doc->documentElement));
            $this->assertSame('#' . $doc->getElementsByTagName('infDPS')->item(0)->getAttribute('Id'),
                $doc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Reference')->item(0)->getAttribute('URI'));
            $this->assertTrue($signer->verificar($doc, 'infDPS'), "assinatura {$alg} deve verificar");

            // adulteração invalida
            $doc->getElementsByTagName('vServ')->item(0)->nodeValue = '1.00';
            $this->assertFalse($signer->verificar($doc, 'infDPS'));
        }
    }

    public function test_emissor_nacional_autoriza_rejeita_e_indefinido(): void
    {
        [$empresa, $nota] = $this->cenario();
        $parcela = new FinInstallment(['descricao' => 'x', 'ret_irrf' => 0, 'ret_csll' => 0, 'ret_pis' => 0, 'ret_cofins' => 0, 'ret_iss' => 0]);
        $emissor = new class(app(DpsXmlBuilder::class), app(XmlSigner::class), $parcela) extends NacionalNfseEmissor {
            public function __construct($b, $s, private $p) { parent::__construct($b, $s); }
            protected function parcela(FinInvoice $nota): FinInstallment { return $this->p; }
        };

        $nfse = gzencode('<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe><nNFSe>77</nNFSe></infNFSe></NFSe>');
        Http::fakeSequence()
            ->push(['chaveAcesso' => str_repeat('1', 50), 'nfseXmlGZipB64' => base64_encode($nfse), 'alertas' => []], 201)
            ->push(['erros' => [['Codigo' => 'E0138', 'Descricao' => 'CEP inexistente']]], 400)
            ->push('erro', 503);

        $ok = $emissor->emitir($nota, $empresa);
        $this->assertTrue($ok->autorizada);
        $this->assertSame('77', $ok->numero);
        $this->assertStringContainsString('<Signature', $ok->xmlDps);
        Http::assertSent(function ($req) {
            $dps = gzdecode(base64_decode($req['dpsXmlGZipB64']));
            return str_ends_with($req->url(), '/API/SefinNacional/nfse') && str_contains($dps, '<infDPS Id="DPS');
        });

        $rej = $emissor->emitir($nota, $empresa);
        $this->assertFalse($rej->autorizada);
        $this->assertFalse($rej->indefinido);
        $this->assertSame('E0138', $rej->mensagens[0]['codigo']);

        $ind = $emissor->emitir($nota, $empresa);
        $this->assertTrue($ind->indefinido, '5xx = indefinido: conferir antes de reenviar');
    }
}
