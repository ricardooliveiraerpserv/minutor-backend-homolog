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
        // Fonte de "recebido" é POR EMPRESA (multi-empresa): ERPSERV puxa do Keruak;
        // outras empresas (ex.: BIZIFY) vêm de URL própria OU de um JSON estático.
        // Sem fonte configurada → vazio (não vaza o recebido de outra empresa).
        $slug     = $this->companySlug();
        $cacheKey = self::CACHE_KEY . ':' . $slug;

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addHours(3), function () use ($slug) {
            // 1) URL do Keruak da empresa (config por slug; erpserv tem a URL legada/default).
            $url = SystemSetting::get("keruak_rentabilidade_url:{$slug}")
                ?: ($slug === 'erpserv'
                    ? (SystemSetting::get('keruak_rentabilidade_url') ?: self::DEFAULT_URL)
                    : null);

            if ($url) {
                try {
                    $resp = Http::timeout(60)->get($url);
                    if (!$resp->successful()) {
                        Log::warning('[KERUAK] resposta não-ok', ['status' => $resp->status(), 'company' => $slug]);
                        return [];
                    }
                    return $this->parse($resp->body());
                } catch (\Throwable $e) {
                    Log::warning('[KERUAK] falha ao buscar recebimentos', ['error' => $e->getMessage(), 'company' => $slug]);
                    return [];
                }
            }

            // 2) JSON estático da empresa (ex.: BIZIFY fornece um JSON de recebimentos).
            $json = SystemSetting::get("keruak_recebido_json:{$slug}");
            if ($json) {
                $decoded = is_array($json) ? $json : json_decode((string) $json, true);
                if (is_array($decoded)) {
                    return $this->normalizeJson($decoded);
                }
            }

            return []; // empresa sem fonte de recebido → vazio (não vaza de outra empresa)
        });
    }

    /** Slug da empresa ATIVA (default erpserv fora de contexto de empresa). */
    private function companySlug(): string
    {
        $id = app(\App\Services\CompanyContext::class)->id();
        if (!$id) {
            return 'erpserv';
        }
        return \App\Models\Company::whereKey($id)->value('slug') ?: 'erpserv';
    }

    /**
     * Normaliza um JSON de recebidos da empresa para o formato interno
     * `{ CNPJ(dígitos): { name, receb: { 'YYYY-MM': float } } }`. Aceita já nesse
     * formato; se vier como lista de {cnpj,name,receb} também converte.
     */
    private function normalizeJson(array $data): array
    {
        // Já no formato final (chaveado por CNPJ)?
        $first = reset($data);
        if (is_array($first) && array_key_exists('receb', $first)) {
            $out = [];
            foreach ($data as $cnpj => $row) {
                $key = preg_replace('/\D/', '', (string) ($row['cnpj'] ?? $cnpj));
                $out[$key] = ['name' => $row['name'] ?? '', 'receb' => (array) ($row['receb'] ?? []), 'receita_total' => (array) ($row['receita_total'] ?? $row['receb'] ?? []), 'em_aberto' => (float) ($row['em_aberto'] ?? 0)];
            }
            return $out;
        }
        // Lista de linhas [{cnpj,name,receb:{...}}]
        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) continue;
            $key = preg_replace('/\D/', '', (string) ($row['cnpj'] ?? ''));
            if ($key === '') continue;
            $out[$key] = ['name' => $row['name'] ?? '', 'receb' => (array) ($row['receb'] ?? []), 'receita_total' => (array) ($row['receita_total'] ?? $row['receb'] ?? []), 'em_aberto' => (float) ($row['em_aberto'] ?? 0)];
        }
        return $out;
    }

    private function parse(string $html): array
    {
        if (!preg_match('/<tbody>(.*)<\/tbody>/s', $html, $mb)) {
            return [];
        }
        preg_match_all('/<tr>(.*?)<\/tr>/s', $mb[1], $rows);

        // Layout ATUAL do relatório Keruak (colunas):
        // [0]Razão [1]CNPJ [2]AnoMesEmissao [3]Valor da Parcela [4]Valor Recebido
        // [5]Valor da Multa [6]Mês-Ano Recebimento [7]Empresa [8]Observação.
        // Receita Total = Parcela+Multa (base da margem); Recebido = informativo;
        // Em Aberto = Parcela+Multa quando Recebido=0 (título ainda não pago).
        $money = fn ($cell) => (float) str_replace(',', '.', str_replace('.', '', trim(strip_tags((string) $cell))));

        $out = [];
        foreach ($rows[1] as $r) {
            preg_match_all('/<td>(.*?)<\/td>/s', $r, $c);
            $cells = $c[1] ?? [];
            if (count($cells) < 7) {
                continue;
            }
            $name = trim(html_entity_decode(strip_tags($cells[0])));
            $cnpj = preg_replace('/\D/', '', $cells[1]);
            if (!$cnpj) {
                continue;
            }
            $parcela  = $money($cells[3]);
            $recebido = $money($cells[4]);
            $multa    = $money($cells[5]);
            $recebRaw = trim(strip_tags($cells[6])); // "MM-YYYY" (VAZIO = título ainda NÃO recebido)
            $receitaTotal = round($parcela + $multa, 2);
            $empresa    = trim(html_entity_decode(strip_tags($cells[7] ?? '')));
            $observacao = trim(html_entity_decode(strip_tags($cells[8] ?? '')));
            $emissao = preg_match('/^(\d{2})-(\d{4})$/', trim(strip_tags($cells[2] ?? '')), $me) ? ($me[2] . '-' . $me[1]) : null;

            if (!isset($out[$cnpj])) {
                $out[$cnpj] = ['name' => $name, 'receb' => [], 'receita_total' => [], 'em_aberto' => 0.0, 'titulos' => []];
            }
            if (empty($out[$cnpj]['name'])) $out[$cnpj]['name'] = $name;

            // A RECEBER (regra do negócio): TODO título com Valor Recebido = 0 é "a receber",
            // independente de ter ou não mês de recebimento. "Em Aberto" é um SALDO ATUAL por
            // cliente (não por mês) — soma flat por CNPJ.
            $emAb = ($recebido <= 0 && $receitaTotal > 0) ? $receitaTotal : 0.0;
            if ($emAb > 0) {
                $out[$cnpj]['em_aberto'] = round($out[$cnpj]['em_aberto'] + $emAb, 2);
            }

            // Sem mês de recebimento = ainda não recebido: entra só no "a receber" (acima), não em
            // receb/receita_total (que são indexados pelo mês de recebimento).
            if (!preg_match('/^(\d{2})-(\d{4})$/', $recebRaw, $mm)) {
                $out[$cnpj]['titulos'][] = [
                    'emissao' => $emissao, 'recebimento' => null, 'valor' => round($recebido, 2),
                    'parcela' => round($parcela, 2), 'multa' => round($multa, 2),
                    'receita_total' => $receitaTotal, 'em_aberto' => $emAb,
                    'empresa' => $empresa, 'observacao' => $observacao,
                ];
                continue;
            }
            $ym = $mm[2] . '-' . $mm[1];

            $out[$cnpj]['receb'][$ym]         = round(($out[$cnpj]['receb'][$ym] ?? 0) + $recebido, 2);
            $out[$cnpj]['receita_total'][$ym] = round(($out[$cnpj]['receita_total'][$ym] ?? 0) + $receitaTotal, 2);
            // Detalhe por título — usado pelo drill-down. 'valor' = recebido (compat).
            $out[$cnpj]['titulos'][] = [
                'emissao'       => $emissao,
                'recebimento'   => $ym,
                'valor'         => round($recebido, 2),
                'parcela'       => round($parcela, 2),
                'multa'         => round($multa, 2),
                'receita_total' => $receitaTotal,
                'em_aberto'     => $emAb,
                'empresa'       => $empresa,
                'observacao'    => $observacao,
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
    public function titulos(array $cnpjs, array $recebMonths = [], bool $fresh = false, string $mode = 'recebido'): array
    {
        $map = $this->recebido($fresh);
        $months = array_flip(array_filter($recebMonths));
        $aberto = $mode === 'aberto'; // 'aberto' = títulos a receber (Recebido=0); senão = recebidos

        $titulos = [];
        $total = 0.0;
        foreach (array_unique(array_filter($cnpjs)) as $cnpj) {
            $cnpj = preg_replace('/\D/', '', (string) $cnpj);
            foreach (($map[$cnpj]['titulos'] ?? []) as $t) {
                if ($aberto) {
                    if ((float) ($t['em_aberto'] ?? 0) <= 0) {
                        continue; // só os que estão a receber
                    }
                    $titulos[] = $t + ['cnpj' => $cnpj, 'cliente' => $map[$cnpj]['name'] ?? null];
                    $total += (float) $t['em_aberto'];
                } else {
                    if ($months && !isset($months[$t['recebimento']])) {
                        continue;
                    }
                    $titulos[] = $t + ['cnpj' => $cnpj, 'cliente' => $map[$cnpj]['name'] ?? null];
                    $total += (float) $t['valor'];
                }
            }
        }

        // Recebidos: mais recentes por recebimento. A receber: mais recentes por emissão.
        usort($titulos, function ($a, $b) use ($aberto) {
            return $aberto
                ? [$b['emissao'], $b['em_aberto']] <=> [$a['emissao'], $a['em_aberto']]
                : [$b['recebimento'], $b['valor']] <=> [$a['recebimento'], $a['valor']];
        });

        return ['titulos' => $titulos, 'total' => round($total, 2)];
    }
}
