<?php

namespace App\Services\Financeiro\Nfse;

use App\Models\Financeiro\FinCompanySetting;
use App\Models\Financeiro\FinInstallment;
use App\Models\Financeiro\FinInvoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Sistema Nacional NFS-e — SEFIN Nacional (Manual dos Contribuintes, API do Emissor Público):
 *   POST {base}/nfse            → gera a NFS-e a partir da DPS (síncrono)
 *   GET  {base}/dps/{idDps}     → chave da NFS-e gerada a partir de uma DPS (conferência pós-timeout)
 *   POST {base}/nfse/{chave}/eventos → eventos (cancelamento) — F3b
 * Autenticação: mTLS com o certificado A1 (ICP-Brasil) da empresa. Corpo JSON com a DPS assinada,
 * GZip + Base64 (campo configurável, padrão "dpsXmlGZipB64").
 */
class NacionalNfseEmissor implements NfseEmissor
{
    public function __construct(private readonly DpsXmlBuilder $builder, private readonly XmlSigner $signer) {}

    public function nome(): string
    {
        return 'nacional';
    }

    public function emitir(FinInvoice $nota, FinCompanySetting $empresa): NfseResultado
    {
        $parcela = $this->parcela($nota);
        [$cert, $key] = $this->pem($empresa);

        $doc = $this->builder->construir($nota, $empresa, $parcela);
        $this->signer->assinar($doc, 'infDPS', $cert, $key);
        $xml = $doc->saveXML($doc->documentElement); // sem declaração XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . $xml;

        $base = rtrim(config('financeiro.nfse_api.' . ($empresa->nfse_ambiente === 'producao' ? 'producao' : 'homologacao')), '/');
        $campo = config('financeiro.nfse_api.campo_dps', 'dpsXmlGZipB64');

        $tmp = $this->arquivosTemporarios($cert, $key);
        try {
            $resp = Http::withOptions(['cert' => $tmp['cert'], 'ssl_key' => $tmp['key'], 'connect_timeout' => 15])
                ->timeout(60)->acceptJson()->asJson()
                ->post($base . '/nfse', [$campo => base64_encode(gzencode($xml))]);
        } catch (ConnectionException $e) {
            return new NfseResultado(false, xmlDps: $xml, indefinido: true,
                mensagens: [['codigo' => 'CONEXAO', 'descricao' => 'Sem resposta da SEFIN Nacional: ' . mb_substr($e->getMessage(), 0, 300)]]);
        } finally {
            @unlink($tmp['cert']);
            @unlink($tmp['key']);
        }

        $json = $resp->json() ?? [];
        if ($resp->serverError()) {
            return new NfseResultado(false, xmlDps: $xml, indefinido: true,
                mensagens: [['codigo' => 'HTTP' . $resp->status(), 'descricao' => 'Erro do servidor da SEFIN — conferir a DPS antes de reenviar.']]);
        }

        $chave = $this->campo($json, ['chaveAcesso', 'ChaveAcesso', 'chave_acesso']);
        $nfseB64 = $this->campo($json, ['nfseXmlGZipB64', 'NfseXmlGZipB64']);
        if ($resp->successful() && $chave) {
            $xmlNfse = $nfseB64 ? @gzdecode(base64_decode($nfseB64)) : null;
            return new NfseResultado(
                autorizada: true,
                numero: $xmlNfse ? $this->tag($xmlNfse, 'nNFSe') : null,
                chaveAcesso: $chave,
                codigoVerificacao: null,
                xmlDps: $xml,
                xmlNfse: $xmlNfse ?: null,
                mensagens: $this->mensagens($json, 'alertas'),
            );
        }

        return new NfseResultado(false, xmlDps: $xml, mensagens: $this->mensagens($json, 'erros')
            ?: [['codigo' => 'HTTP' . $resp->status(), 'descricao' => mb_substr($resp->body(), 0, 500)]]);
    }

    protected function parcela(FinInvoice $nota): FinInstallment
    {
        return FinInstallment::withoutGlobalScopes()->findOrFail($nota->installment_id);
    }

    /** PEM (cert, chave) a partir do .pfx cifrado da empresa. */
    private function pem(FinCompanySetting $empresa): array
    {
        $pfx = base64_decode((string) $empresa->certificado_pfx);
        $certs = [];
        if (! $pfx || ! openssl_pkcs12_read($pfx, $certs, (string) $empresa->certificado_senha)) {
            throw new \RuntimeException('Não foi possível abrir o certificado A1 da empresa.');
        }
        return [$certs['cert'], $certs['pkey']];
    }

    /** Guzzle precisa de arquivos: criados 0600 em diretório privado e apagados após a chamada. */
    private function arquivosTemporarios(string $cert, string $key): array
    {
        $dir = storage_path('app/private/nfse-tmp');
        if (! is_dir($dir)) @mkdir($dir, 0700, true);
        $c = tempnam($dir, 'c');
        $k = tempnam($dir, 'k');
        chmod($c, 0600);
        chmod($k, 0600);
        file_put_contents($c, $cert);
        file_put_contents($k, $key);
        return ['cert' => $c, 'key' => $k];
    }

    private function campo(array $j, array $nomes): ?string
    {
        foreach ($nomes as $n) if (! empty($j[$n])) return (string) $j[$n];
        return null;
    }

    private function mensagens(array $j, string $chave): array
    {
        $lista = $j[$chave] ?? $j[ucfirst($chave)] ?? [];
        if (! is_array($lista)) return [];
        return array_values(array_map(fn ($m) => [
            'codigo' => (string) ($m['Codigo'] ?? $m['codigo'] ?? ''),
            'descricao' => trim(($m['Descricao'] ?? $m['descricao'] ?? '') . ' ' . ($m['Complemento'] ?? $m['complemento'] ?? '')),
        ], array_filter($lista, 'is_array')));
    }

    private function tag(string $xml, string $tag): ?string
    {
        return preg_match('/<' . $tag . '>([^<]+)<\/' . $tag . '>/', $xml, $m) ? $m[1] : null;
    }
}
