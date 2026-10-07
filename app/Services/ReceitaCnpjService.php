<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consulta de CNPJ na Receita Federal via BrasilAPI (gratuita, sem chave) e
 * inferência do regime tributário — base para o levantamento da Reforma
 * Tributária (quais consultores são MEI × Simples Nacional × demais).
 *
 * Regra de classificação:
 *   opcao_pelo_mei = true      → mei
 *   senão opcao_pelo_simples   → simples_nacional
 *   senão                      → lucro_presumido (demais / não optante)
 */
class ReceitaCnpjService
{
    /**
     * @return array{tax_regime:string,razao_social:?string,porte:?string,opcao_pelo_mei:bool,opcao_pelo_simples:bool,situacao:?string}|null
     *         null quando o CNPJ é inválido ou a consulta falha.
     */
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

        try {
            $res = Http::timeout(15)->acceptJson()
                ->get("https://brasilapi.com.br/api/cnpj/v1/{$digits}");
        } catch (\Throwable $e) {
            Log::warning('ReceitaCnpjService: falha ao consultar BrasilAPI', [
                'cnpj' => $digits,
                'erro' => $e->getMessage(),
            ]);
            return null;
        }

        if (! $res->successful()) {
            return null;
        }

        $d       = $res->json();
        $mei     = (bool) ($d['opcao_pelo_mei'] ?? false);
        $simples = (bool) ($d['opcao_pelo_simples'] ?? false);
        $regime  = $mei ? 'mei' : ($simples ? 'simples_nacional' : 'lucro_presumido');

        $out = [
            'tax_regime'         => $regime,
            'razao_social'       => $d['razao_social'] ?? null,
            'porte'              => $d['porte'] ?? ($d['descricao_porte'] ?? null),
            'opcao_pelo_mei'     => $mei,
            'opcao_pelo_simples' => $simples,
            'situacao'           => $d['descricao_situacao_cadastral'] ?? null,
        ];

        // Cacheia só o sucesso (falhas transitórias não devem "grudar").
        Cache::put($cacheKey, $out, now()->addDays(30));

        return $out;
    }
}
