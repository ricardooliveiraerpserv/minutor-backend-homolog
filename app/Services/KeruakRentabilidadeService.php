<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integração com o BI de Rentabilidade do Keruak ("RECEBIMENTO POR CLIENTE").
 * O endpoint retorna uma TABELA HTML (não JSON) com: Razão, CNPJ, AnoMesEmissao,
 * Valor Recebido, Mês-Ano Recebimento, Empresa, Observação. Parseamos e
 * agregamos por CNPJ × mês de recebimento (somando o valor recebido).
 */
class KeruakRentabilidadeService
{
    private const DEFAULT_URL = 'https://app.keruak.com/cgi-bin/Relatorios/powerbi?id=ZXJwc2VydmNvO0ZBOTJBNTE0LTE0RkUtRUYxMS05Q0QxLTAyMkJFMEVBOEFDOQ==';
    // v2: passou a guardar 'titulos' por CNPJ (drill-down do Valor Recebido).
    private const CACHE_KEY    = 'keruak:recebido-map:v2';

    /**
     * @return array<string, array{name: string, receb: array<string, float>}>
     *   indexado por CNPJ (só dígitos); receb = ['YYYY-MM' => valor somado].
     */
    public function recebido(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addHours(3), function () {
            $url = SystemSetting::get('keruak_rentabilidade_url') ?: self::DEFAULT_URL;
            try {
                $resp = Http::timeout(60)->get($url);
                if (!$resp->successful()) {
                    Log::warning('[KERUAK] resposta não-ok', ['status' => $resp->status()]);
                    return [];
                }
                return $this->parse($resp->body());
            } catch (\Throwable $e) {
                Log::warning('[KERUAK] falha ao buscar recebimentos', ['error' => $e->getMessage()]);
                return [];
            }
        });
    }

    private function parse(string $html): array
    {
        if (!preg_match('/<tbody>(.*)<\/tbody>/s', $html, $mb)) {
            return [];
        }
        preg_match_all('/<tr>(.*?)<\/tr>/s', $mb[1], $rows);

        $out = [];
        foreach ($rows[1] as $r) {
            preg_match_all('/<td>(.*?)<\/td>/s', $r, $c);
            $cells = $c[1] ?? [];
            if (count($cells) < 5) {
                continue;
            }
            $name = trim(html_entity_decode(strip_tags($cells[0])));
            $cnpj = preg_replace('/\D/', '', $cells[1]);
            if (!$cnpj) {
                continue;
            }
            // "4.457,87" -> 4457.87
            $valor = (float) str_replace(',', '.', str_replace('.', '', trim(strip_tags($cells[3]))));
            $recebRaw = trim(strip_tags($cells[4])); // "MM-YYYY"
            if (!preg_match('/^(\d{2})-(\d{4})$/', $recebRaw, $mm)) {
                continue;
            }
            $ym = $mm[2] . '-' . $mm[1];

            // Emissão "MM-YYYY" -> "YYYY-MM" (col 2). Empresa (col 5) e Observação
            // (col 6, normalmente o código/descrição do projeto) são opcionais.
            $emissaoRaw = trim(strip_tags($cells[2] ?? ''));
            $emissao = preg_match('/^(\d{2})-(\d{4})$/', $emissaoRaw, $me) ? ($me[2] . '-' . $me[1]) : null;
            $empresa    = trim(html_entity_decode(strip_tags($cells[5] ?? '')));
            $observacao = trim(html_entity_decode(strip_tags($cells[6] ?? '')));

            if (!isset($out[$cnpj])) {
                $out[$cnpj] = ['name' => $name, 'receb' => [], 'titulos' => []];
            }
            $out[$cnpj]['receb'][$ym] = round(($out[$cnpj]['receb'][$ym] ?? 0) + $valor, 2);
            // Detalhe por título — usado pelo drill-down do "Valor Recebido".
            $out[$cnpj]['titulos'][] = [
                'emissao'     => $emissao,
                'recebimento' => $ym,
                'valor'       => round($valor, 2),
                'empresa'     => $empresa,
                'observacao'  => $observacao,
            ];
        }

        return $out;
    }

    /**
     * Títulos do Keruak para um conjunto de CNPJs, filtrados pelos meses de
     * recebimento (YYYY-MM). Usado pelo drill-down da célula "Valor Recebido".
     *
     * @param string[] $cnpjs        CNPJs (só dígitos)
     * @param string[] $recebMonths  meses de recebimento YYYY-MM (vazio = todos)
     * @return array{titulos: array<int, array<string, mixed>>, total: float}
     */
    public function titulos(array $cnpjs, array $recebMonths = [], bool $fresh = false): array
    {
        $map = $this->recebido($fresh);
        $months = array_flip(array_filter($recebMonths));

        $titulos = [];
        $total = 0.0;
        foreach (array_unique(array_filter($cnpjs)) as $cnpj) {
            $cnpj = preg_replace('/\D/', '', (string) $cnpj);
            foreach (($map[$cnpj]['titulos'] ?? []) as $t) {
                if ($months && !isset($months[$t['recebimento']])) {
                    continue;
                }
                $titulos[] = $t + ['cnpj' => $cnpj, 'cliente' => $map[$cnpj]['name'] ?? null];
                $total += (float) $t['valor'];
            }
        }

        // Mais recentes primeiro; dentro do mês, maior valor primeiro.
        usort($titulos, function ($a, $b) {
            return [$b['recebimento'], $b['valor']] <=> [$a['recebimento'], $a['valor']];
        });

        return ['titulos' => $titulos, 'total' => round($total, 2)];
    }
}
