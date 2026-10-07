<?php

namespace App\Services;

use App\Exceptions\ReceitaUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consulta de CNPJ na Receita Federal e inferência do regime tributário —
 * base para o levantamento da Reforma Tributária (MEI × Simples × demais).
 *
 * Usa dois provedores gratuitos em cascata (o 1º costuma cair porque a
 * Receita/upstream oscila — já vimos HTTP 500/503):
 *   1) BrasilAPI    (brasilapi.com.br)
 *   2) CNPJa Open   (open.cnpja.com)  — fallback
 *
 * Regra de classificação:
 *   MEI (simei)  → mei
 *   senão Simples → simples_nacional
 *   senão         → lucro_presumido (demais / não optante)
 *
 * Retorno:
 *   array  → dados encontrados
 *   null   → CNPJ inválido ou NÃO encontrado (resposta definitiva de 404)
 *   throw ReceitaUnavailableException → nenhum provedor respondeu (tente depois)
 */
class ReceitaCnpjService
{
    public function lookup(string $cnpj): ?array
    {
        $digits = preg_replace('/\D/', '', $cnpj);
        if (strlen($digits) !== 14) {
            return null;
        }

        $cacheKey = "cnpj_receita_{$digits}";
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $anyUnavailable = false;

        foreach (['fetchBrasilApi', 'fetchCnpjaOpen'] as $provider) {
            try {
                $out = $this->{$provider}($digits);
            } catch (ReceitaUnavailableException $e) {
                $anyUnavailable = true;
                Log::info("ReceitaCnpjService: {$provider} indisponível", ['cnpj' => $digits, 'erro' => $e->getMessage()]);
                continue; // tenta o próximo provedor
            }

            if ($out !== null) {
                Cache::put($cacheKey, $out, now()->addDays(30)); // cacheia só sucesso
                return $out;
            }
            // $out === null → provedor respondeu "não encontrado" (404). Ainda assim
            // tenta o próximo, pois um pode ter o CNPJ que o outro não tem.
        }

        // Nenhum provedor trouxe dados. Se algum caiu, é indisponibilidade;
        // se todos responderam 404, é realmente não encontrado.
        if ($anyUnavailable) {
            throw new ReceitaUnavailableException('Nenhum provedor de CNPJ respondeu.');
        }

        return null;
    }

    /** @return array|null  array=ok, null=404 (não encontrado). Lança em 5xx/timeout. */
    private function fetchBrasilApi(string $digits): ?array
    {
        try {
            $res = Http::timeout(12)->acceptJson()->get("https://brasilapi.com.br/api/cnpj/v1/{$digits}");
        } catch (\Throwable $e) {
            throw new ReceitaUnavailableException("BrasilAPI: {$e->getMessage()}");
        }

        if ($res->status() === 404 || $res->status() === 400) {
            return null;
        }
        if (! $res->successful()) {
            throw new ReceitaUnavailableException("BrasilAPI HTTP {$res->status()}");
        }

        $d       = $res->json();
        $mei     = (bool) ($d['opcao_pelo_mei'] ?? false);
        $simples = (bool) ($d['opcao_pelo_simples'] ?? false);

        return $this->normalize(
            razao:   $d['razao_social'] ?? null,
            porte:   $d['porte'] ?? ($d['descricao_porte'] ?? null),
            mei:     $mei,
            simples: $simples,
            situacao: $d['descricao_situacao_cadastral'] ?? null,
        );
    }

    /** @return array|null  array=ok, null=404. Lança em 5xx/429/timeout. */
    private function fetchCnpjaOpen(string $digits): ?array
    {
        try {
            $res = Http::timeout(12)->acceptJson()->get("https://open.cnpja.com/office/{$digits}");
        } catch (\Throwable $e) {
            throw new ReceitaUnavailableException("CNPJa: {$e->getMessage()}");
        }

        if ($res->status() === 404) {
            return null;
        }
        if (! $res->successful()) {
            throw new ReceitaUnavailableException("CNPJa HTTP {$res->status()}");
        }

        $d       = $res->json();
        $mei     = (bool) data_get($d, 'company.simei.optant', false);
        $simples = (bool) data_get($d, 'company.simples.optant', false);

        return $this->normalize(
            razao:   data_get($d, 'company.name'),
            porte:   data_get($d, 'company.size.text'),
            mei:     $mei,
            simples: $simples,
            situacao: data_get($d, 'status.text'),
        );
    }

    private function normalize(?string $razao, ?string $porte, bool $mei, bool $simples, ?string $situacao): array
    {
        return [
            'tax_regime'         => $mei ? 'mei' : ($simples ? 'simples_nacional' : 'lucro_presumido'),
            'razao_social'       => $razao,
            'porte'              => $porte,
            'opcao_pelo_mei'     => $mei,
            'opcao_pelo_simples' => $simples,
            'situacao'           => $situacao,
        ];
    }
}
