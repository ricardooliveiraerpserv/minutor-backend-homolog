<?php

namespace App\Http\Controllers;

use App\Models\ClientSourceRepo;
use App\Models\EnvEnvironment;
use App\Models\ProsightRpoConfig;
use App\Prosight\RpoInventoryService;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Configuração REST AdvPL (RPO) por AMBIENTE — paridade com o configurador do ProSight enviado.
 * O SERVIDOR consulta o RPO direto (rpoApiUrl + Basic auth). Senha cifrada em repouso, nunca
 * retornada (só flag). Escopo: admin + acesso ao customer do ambiente (anti-IDOR 404).
 */
class ProsightRpoConfigController extends Controller
{
    public function __construct(private SourceDocCustomerScope $scope)
    {
    }

    private function envOrNull(Request $request, int $environmentId): ?EnvEnvironment
    {
        $env = EnvEnvironment::query()->whereKey($environmentId)->first(['id', 'customer_id', 'name']);
        if (! $env || ! $this->scope->canAccessCustomerId($request->user(), (int) $env->customer_id)) {
            return null;
        }
        return $env;
    }

    /** GET /prosight/environments/{environmentId}/rpo-config — campos seguros + flag da senha. */
    public function show(Request $request, int $environmentId): JsonResponse
    {
        $env = $this->envOrNull($request, $environmentId);
        if (! $env) {
            return response()->json(['message' => 'Ambiente não encontrado.'], 404);
        }
        $cfg = ProsightRpoConfig::where('environment_id', $env->id)->first();

        return response()->json(['data' => [
            'environment_id'         => (int) $env->id,
            'rpo_api_url'            => $cfg?->rpo_api_url,
            'rpo_api_user'           => $cfg?->rpo_api_user,
            'rpo_api_password_set'   => (bool) ($cfg?->rpo_api_password),   // nunca a senha em si
            'rpo_exclusion_patterns' => $cfg?->rpo_exclusion_patterns ?? '',
            'allow_insecure_tls'     => (bool) ($cfg?->allow_insecure_tls),
        ]]);
    }

    /** PUT /prosight/environments/{environmentId}/rpo-config — salva; senha só se enviada. */
    public function update(Request $request, int $environmentId): JsonResponse
    {
        $env = $this->envOrNull($request, $environmentId);
        if (! $env) {
            return response()->json(['message' => 'Ambiente não encontrado.'], 404);
        }
        $data = $request->validate([
            'rpo_api_url'            => 'nullable|string|max:500|url',
            'rpo_api_user'           => 'nullable|string|max:120',
            'rpo_api_password'       => 'nullable|string|max:200',   // vazio = manter atual
            'rpo_exclusion_patterns' => 'nullable|string|max:1000',
            'allow_insecure_tls'     => 'nullable|boolean',
        ]);

        $cfg = ProsightRpoConfig::firstOrNew(['environment_id' => $env->id]);
        $cfg->rpo_api_url            = $data['rpo_api_url'] ?? null;
        $cfg->rpo_api_user           = $data['rpo_api_user'] ?? null;
        $cfg->rpo_exclusion_patterns = $data['rpo_exclusion_patterns'] ?? '';
        $cfg->allow_insecure_tls     = (bool) ($data['allow_insecure_tls'] ?? false);
        // Senha: só sobrescreve se veio preenchida (branco = manter a atual).
        if (! empty($data['rpo_api_password'])) {
            $cfg->rpo_api_password = $data['rpo_api_password'];
        }
        $cfg->updated_by = $request->user()?->id;
        $cfg->save();

        return response()->json(['data' => ['saved' => true, 'rpo_api_password_set' => (bool) $cfg->rpo_api_password]]);
    }

    /**
     * POST /prosight/environments/{environmentId}/rpo-config/test — probe do endpoint AdvPL.
     * Usa a config salva; permite override no corpo (p/ testar antes de salvar). Espelha o
     * fetchRpoFromAdvPL do ProSight enviado: POST {programs:['_PROSIGHT_CHECK_']} + Basic auth;
     * HTML → credencial errada; não-array → erro; timeout 15s.
     */
    public function test(Request $request, int $environmentId): JsonResponse
    {
        $env = $this->envOrNull($request, $environmentId);
        if (! $env) {
            return response()->json(['message' => 'Ambiente não encontrado.'], 404);
        }
        $cfg = ProsightRpoConfig::where('environment_id', $env->id)->first();

        $url      = $request->input('rpo_api_url')  ?: $cfg?->rpo_api_url;
        $user     = $request->input('rpo_api_user') ?: $cfg?->rpo_api_user;
        // Senha: usa a do corpo se enviada, senão a salva (decifrada).
        $password = $request->filled('rpo_api_password') ? $request->input('rpo_api_password') : $cfg?->rpo_api_password;
        $insecure = $request->has('allow_insecure_tls') ? (bool) $request->boolean('allow_insecure_tls') : (bool) ($cfg?->allow_insecure_tls);

        if (! $url) {
            return response()->json(['data' => ['ok' => false, 'message' => 'rpoApiUrl não configurado. Preencha a URL do endpoint AdvPL.']], 200);
        }
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['data' => ['ok' => false, 'message' => "URL inválida: {$url}"]], 200);
        }

        try {
            $client = Http::timeout(15)->acceptJson()->asJson();
            if ($insecure) {
                $client = $client->withoutVerifying(); // on-prem cert self-signed
            }
            if ($user && $password) {
                $client = $client->withBasicAuth($user, $password);
            }
            $resp = $client->post($url, ['programs' => ['_PROSIGHT_CHECK_']]);

            $bodyRaw = ltrim($resp->body());
            if ($bodyRaw !== '' && $bodyRaw[0] === '<') {
                return response()->json(['data' => ['ok' => false,
                    'message' => "Endpoint AdvPL retornou HTML (HTTP {$resp->status()}) — verifique usuário e senha."]], 200);
            }
            $json = $resp->json();
            if (! is_array($json) || array_is_list($json) === false) {
                $preview = mb_substr(is_string($resp->body()) ? $resp->body() : json_encode($json), 0, 200);
                return response()->json(['data' => ['ok' => false,
                    'message' => "Resposta AdvPL não é um array JSON (HTTP {$resp->status()}): {$preview}"]], 200);
            }

            return response()->json(['data' => ['ok' => true,
                'message' => 'Conexão AdvPL ok — endpoint respondeu um array JSON.',
                'status'  => $resp->status(), 'sample_count' => count($json)]], 200);
        } catch (\Throwable $e) {
            return response()->json(['data' => ['ok' => false,
                'message' => 'Falha ao chamar o endpoint AdvPL: ' . mb_substr($e->getMessage(), 0, 200)]], 200);
        }
    }

    /**
     * POST /prosight/environments/{environmentId}/rpo-inventory/scan — Inventário Git × RPO.
     * Clona (blobless) os repos da empresa, obtém a data do último commit por fonte, consulta o RPO
     * e compara (sincronizado/recompilar/verificar_rpo/nao_compilado/so_rpo) + exclusões + resumo.
     */
    public function scan(Request $request, int $environmentId, RpoInventoryService $service): JsonResponse
    {
        $env = $this->envOrNull($request, $environmentId);
        if (! $env) {
            return response()->json(['message' => 'Ambiente não encontrado.'], 404);
        }
        $repos = ClientSourceRepo::where('customer_id', $env->customer_id)
            ->where('active', true)->whereNull('deleted_at')->get();

        $result = $service->scan($env, $repos->all());

        // Cacheia o resumo (leve) para a Visão Geral não re-rodar o scan pesado.
        if (($result['ok'] ?? false) && isset($result['summary'])) {
            ProsightRpoConfig::where('environment_id', $env->id)->update([
                'last_scan_summary' => $result['summary'],
                'last_scan_at' => now(),
            ]);
        }

        return response()->json(['data' => $result], 200);
    }

    /**
     * GET /prosight/companies/{customerId}/rpo-overview — status RPO da EMPRESA (leve, sem scan).
     * Para a Visão Geral: quais ambientes têm RPO configurado + o resumo do último inventário.
     */
    public function companyOverview(Request $request, int $customerId): JsonResponse
    {
        if (! $this->scope->canAccessCustomerId($request->user(), $customerId)) {
            return response()->json(['message' => 'Empresa fora do seu escopo.'], 403);
        }
        $envs = EnvEnvironment::where('customer_id', $customerId)->whereNull('deleted_at')->get(['id', 'name', 'type']);
        $cfgs = ProsightRpoConfig::whereIn('environment_id', $envs->pluck('id'))->get()->keyBy('environment_id');

        $items = $envs->map(function ($e) use ($cfgs) {
            $c = $cfgs->get($e->id);
            return [
                'environment_id' => (int) $e->id,
                'name' => $e->name,
                'type' => $e->type,
                'rpo_configured' => (bool) ($c && $c->rpo_api_url),
                'last_scan_at' => $c?->last_scan_at?->toIso8601String(),
                'summary' => $c?->last_scan_summary,
            ];
        })->values();

        $configuredN = $items->where('rpo_configured', true)->count();

        // Rollup de saúde: soma dos counts dos últimos scans (só ambientes já escaneados).
        $agg = ['sincronizado' => 0, 'recompilar' => 0, 'verificar_rpo' => 0, 'nao_compilado' => 0, 'so_rpo' => 0];
        $total = 0; $scanned = 0;
        foreach ($items as $it) {
            $sum = $it['summary'] ?? null;
            if (! is_array($sum) || ! isset($sum['counts'])) {
                continue;
            }
            $scanned++;
            foreach ($agg as $k => $_) {
                $agg[$k] += (int) ($sum['counts'][$k] ?? 0);
            }
            $total += (int) ($sum['total'] ?? 0);
        }
        $healthPct = $total > 0 ? round(($agg['sincronizado'] / $total) * 1000) / 10 : null;

        return response()->json(['data' => [
            'customer_id' => $customerId,
            'environments' => $items,
            'configured_count' => $configuredN,
            'scanned_count' => $scanned,
            'rollup' => $total > 0 ? ['counts' => $agg, 'total' => $total, 'health_pct' => $healthPct] : null,
        ]], 200);
    }
}
