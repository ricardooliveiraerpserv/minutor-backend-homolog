<?php

namespace App\Services\Financeiro;

use Carbon\Carbon;

/**
 * Lê um certificado A1 (.pfx/.p12) só para VALIDAR e extrair metadados (titular, CNPJ,
 * validade). O conteúdo e a senha são guardados pelo chamador já cifrados; este serviço
 * nunca loga nem devolve o segredo.
 */
class CertificadoA1Service
{
    /**
     * @return array{titular: string, cnpj: ?string, valido_ate: Carbon, valido_desde: Carbon}
     * @throws \InvalidArgumentException certificado ilegível, senha errada ou vencido
     */
    public function inspecionar(string $pfxBinario, string $senha): array
    {
        $certs = [];
        if (! @openssl_pkcs12_read($pfxBinario, $certs, $senha)) {
            throw new \InvalidArgumentException('Não foi possível abrir o certificado: arquivo inválido ou senha incorreta.');
        }
        if (empty($certs['cert']) || empty($certs['pkey'])) {
            throw new \InvalidArgumentException('O arquivo não contém certificado e chave privada.');
        }

        $info = openssl_x509_parse($certs['cert']);
        if (! $info) {
            throw new \InvalidArgumentException('Certificado ilegível.');
        }

        $validoAte = Carbon::createFromTimestamp($info['validTo_time_t']);
        $validoDesde = Carbon::createFromTimestamp($info['validFrom_time_t']);
        if ($validoAte->isPast()) {
            throw new \InvalidArgumentException('Certificado vencido em ' . $validoAte->format('d/m/Y') . '.');
        }

        $cn = (string) ($info['subject']['CN'] ?? '');
        // ICP-Brasil e-CNPJ: "RAZAO SOCIAL:12345678000199"
        $cnpj = null;
        if (preg_match('/(\d{14})\s*$/', $cn, $m)) {
            $cnpj = $m[1];
        }
        $titular = trim(preg_replace('/:\d{14}\s*$/', '', $cn)) ?: $cn;

        return ['titular' => $titular, 'cnpj' => $cnpj, 'valido_ate' => $validoAte, 'valido_desde' => $validoDesde];
    }
}
