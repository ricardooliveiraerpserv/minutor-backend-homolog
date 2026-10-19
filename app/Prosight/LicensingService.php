<?php

namespace App\Prosight;

use App\Models\EnvEnvironment;
use App\Models\ProsightRpoConfig;
use Illuminate\Support\Facades\Http;

/**
 * Licenciamento (REST AdvPL) — consulta o endpoint de licença exposto pelo Protheus do cliente
 * (mesmo padrão do Inventário RPO). Para clientes TOTVS Cloud o agente físico não roda, então a
 * única via é este REST: o ambiente do cliente expõe uma função U_ que lê o License Server e
 * devolve o JSON abaixo.
 *
 * CONTRATO do endpoint (o que o Protheus do cliente deve responder):
 *   POST <licensing_api_url>  (Basic auth)
 *   body: { "dt_ini": "YYYY-MM-DD", "dt_fim": "YYYY-MM-DD", "mode": "usage" | "customs" }
 *
 *   mode=usage  → { periodo, totalEventos, totalUsuarios, picoGlobal:{valor,horario},
 *                   mediaDia, horaPico, modulos:[{sigla,nome,eventos,usuariosUnicos,pico15min}],
 *                   perfis:{full,light,cfgOnly}, atividadePorHora:[24 números] }
 *   mode=customs→ { total, comUso, semUso, itens:[{programa,tipo,execucoes,usuariosUnicos,ultimaExecucao}] }
 *
 * Enquanto o cliente não expõe o endpoint (licensing_api_url vazio), retorna ['vazio'=>true]
 * (estado honesto "não conectado" — nada de dado falso).
 */
class LicensingService
{
    private const TIMEOUT = 30;

    public function fetchUsage(EnvEnvironment $env, string $dtIni, string $dtFim): array
    {
        $cfg = $this->config($env);
        if (! $cfg || ! $cfg->licensing_api_url) {
            return ['vazio' => true];
        }
        try {
            $json = $this->post($cfg, ['dt_ini' => $dtIni, 'dt_fim' => $dtFim, 'mode' => 'usage']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (! is_array($json) || empty($json)) {
            return ['vazio' => true];
        }
        return ['data' => $this->normalizeUsage($json, $dtIni, $dtFim)];
    }

    /**
     * Customs: o FE espera o objeto LicensingCustomsOk DIRETO (não embrulhado em {data}).
     * Sem endpoint configurado → objeto vazio (lista sem itens), não erro.
     */
    public function fetchCustoms(EnvEnvironment $env, string $dtIni, string $dtFim): array
    {
        $cfg = $this->config($env);
        if (! $cfg || ! $cfg->licensing_api_url) {
            return ['total' => 0, 'comUso' => 0, 'semUso' => 0, 'itens' => []];
        }
        try {
            $json = $this->post($cfg, ['dt_ini' => $dtIni, 'dt_fim' => $dtFim, 'mode' => 'customs']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return $this->normalizeCustoms(is_array($json) ? $json : []);
    }

    private function config(EnvEnvironment $env): ?ProsightRpoConfig
    {
        return ProsightRpoConfig::where('environment_id', $env->id)->first();
    }

    /** Monta o cliente HTTP (timeout + TLS inseguro opcional + Basic auth) e faz o POST. */
    private function post(ProsightRpoConfig $cfg, array $body): mixed
    {
        $client = Http::timeout(self::TIMEOUT)->acceptJson()->asJson();
        if ($cfg->allow_insecure_tls) {
            $client = $client->withoutVerifying();
        }
        $user = $cfg->licensingUser();
        $pass = $cfg->licensingPassword();
        if ($user && $pass) {
            $client = $client->withBasicAuth($user, $pass);
        }
        $resp = $client->post($cfg->licensing_api_url, $body);

        $raw = ltrim($resp->body());
        if ($raw !== '' && $raw[0] === '<') {
            throw new \RuntimeException("endpoint retornou HTML (HTTP {$resp->status()}) — verifique a URL, usuário e senha");
        }
        $json = $resp->json();
        if ($json === null && ! $resp->successful()) {
            throw new \RuntimeException("endpoint retornou HTTP {$resp->status()}");
        }
        return $json;
    }

    /** Normaliza p/ o shape LicensingData do FE (defensivo; garante 24 posições em atividadePorHora). */
    private function normalizeUsage(array $j, string $dtIni, string $dtFim): array
    {
        $periodo = is_array($j['periodo'] ?? null) ? $j['periodo'] : [];
        $pico = is_array($j['picoGlobal'] ?? null) ? $j['picoGlobal'] : [];
        $perfis = is_array($j['perfis'] ?? null) ? $j['perfis'] : [];

        $hora = array_values(array_map(fn ($v) => (int) $v, is_array($j['atividadePorHora'] ?? null) ? $j['atividadePorHora'] : []));
        $hora = array_slice(array_pad($hora, 24, 0), 0, 24); // SEMPRE 24

        $modulos = array_map(fn ($m) => [
            'sigla'         => (string) ($m['sigla'] ?? ''),
            'nome'          => (string) ($m['nome'] ?? ''),
            'eventos'       => (int) ($m['eventos'] ?? 0),
            'usuariosUnicos' => (int) ($m['usuariosUnicos'] ?? 0),
            'pico15min'     => (int) ($m['pico15min'] ?? 0),
        ], is_array($j['modulos'] ?? null) ? $j['modulos'] : []);

        return [
            'periodo' => [
                'inicio' => (string) ($periodo['inicio'] ?? ($dtIni . 'T00:00:00Z')),
                'fim'    => (string) ($periodo['fim'] ?? ($dtFim . 'T23:59:59Z')),
                'dias'   => (int) ($periodo['dias'] ?? 0),
            ],
            'totalEventos'  => (int) ($j['totalEventos'] ?? 0),
            'totalUsuarios' => (int) ($j['totalUsuarios'] ?? 0),
            'picoGlobal'    => [
                'valor'   => (int) ($pico['valor'] ?? 0),
                'horario' => (string) ($pico['horario'] ?? ''),
            ],
            'mediaDia' => (int) ($j['mediaDia'] ?? 0),
            'horaPico' => (int) ($j['horaPico'] ?? 0),
            'modulos'  => array_values($modulos),
            'perfis'   => [
                'full'    => (int) ($perfis['full'] ?? 0),
                'light'   => (int) ($perfis['light'] ?? 0),
                'cfgOnly' => (int) ($perfis['cfgOnly'] ?? 0),
            ],
            'atividadePorHora' => $hora,
        ];
    }

    private function normalizeCustoms(array $j): array
    {
        $itens = array_map(fn ($i) => [
            'programa'       => (string) ($i['programa'] ?? ''),
            'tipo'           => strtoupper((string) ($i['tipo'] ?? 'USER')) === 'PARTNER' ? 'PARTNER' : 'USER',
            'execucoes'      => (int) ($i['execucoes'] ?? 0),
            'usuariosUnicos' => (int) ($i['usuariosUnicos'] ?? 0),
            'ultimaExecucao' => (string) ($i['ultimaExecucao'] ?? ''),
        ], is_array($j['itens'] ?? null) ? $j['itens'] : []);

        $comUso = count(array_filter($itens, fn ($i) => $i['execucoes'] > 0));

        return [
            'total'  => (int) ($j['total'] ?? count($itens)),
            'comUso' => (int) ($j['comUso'] ?? $comUso),
            'semUso' => (int) ($j['semUso'] ?? (count($itens) - $comUso)),
            'itens'  => array_values($itens),
        ];
    }
}
