<?php

namespace App\Http\Controllers;

use App\Models\AiProviderConfig;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Configurador → IA (Credenciais). Cadastra a credencial/modelo da IA usada por
 * TODO o sistema (chatbot, documentação de fonte, reuniões, OCR). A chave é
 * gravada CRIPTOGRAFADA (cast `encrypted` no model) e NUNCA retorna no JSON —
 * a tela só vê `has_key`/`key_source`. Quando preenchida, sobrepõe o .env (ver
 * App\Providers\AiServiceProvider::boot).
 */
class AiCredentialController extends Controller
{
    /** Provedores reconhecidos pela tela (slug → defaults de criação). */
    private const KNOWN = [
        'anthropic' => ['name' => 'Anthropic (Claude)', 'api_key_env' => 'ANTHROPIC_API_KEY', 'base_url' => 'https://api.anthropic.com/v1', 'default_model' => 'claude-sonnet-5'],
        'openai'    => ['name' => 'OpenAI',             'api_key_env' => 'OPENAI_API_KEY',    'base_url' => 'https://api.openai.com/v1',    'default_model' => 'gpt-4o-mini'],
    ];

    /** Garante que os provedores conhecidos existam (create-only, não sobrescreve). */
    private function ensureDefaults(): void
    {
        foreach (self::KNOWN as $slug => $d) {
            AiProviderConfig::firstOrCreate(
                ['slug' => $slug],
                ['name' => $d['name'], 'api_key_env' => $d['api_key_env'], 'base_url' => $d['base_url'], 'default_model' => $d['default_model'], 'enabled' => true],
            );
        }
    }

    /** GET /ai-credentials — provedores (sem a chave) + provider padrão. */
    public function index(): JsonResponse
    {
        $this->ensureDefaults();

        $providers = AiProviderConfig::orderBy('id')->get()->map(fn (AiProviderConfig $p) => [
            'id'            => $p->id,
            'slug'          => $p->slug,
            'name'          => $p->name,
            'base_url'      => $p->base_url,
            'default_model' => $p->default_model,
            'enabled'       => (bool) $p->enabled,
            'api_key_env'   => $p->api_key_env,
            'has_key'       => $p->hasKey(),      // existe chave efetiva (db ou env)?
            'key_source'    => $p->keySource(),   // 'db' | 'env' | 'none'
        ])->values();

        return response()->json([
            'data' => [
                'default_provider' => SystemSetting::get('ai.default_provider') ?: config('services.ai.default_provider', 'anthropic'),
                'providers'        => $providers,
            ],
        ]);
    }

    /** PUT /ai-credentials — atualiza provedores em lote + provider padrão. */
    public function update(Request $request): JsonResponse
    {
        $this->ensureDefaults();

        $data = $request->validate([
            'default_provider'          => ['nullable', 'string', 'in:anthropic,openai'],
            'providers'                 => ['nullable', 'array'],
            'providers.*.slug'          => ['required', 'string', 'in:anthropic,openai'],
            'providers.*.api_key'       => ['nullable', 'string', 'max:500'], // preenchido = define; ausente = mantém
            'providers.*.clear_key'     => ['nullable', 'boolean'],           // true = apaga a chave do banco (volta p/ .env)
            'providers.*.default_model' => ['nullable', 'string', 'max:120'],
            'providers.*.base_url'      => ['nullable', 'string', 'max:200'],
            'providers.*.enabled'       => ['nullable', 'boolean'],
        ]);

        foreach (($data['providers'] ?? []) as $row) {
            $p = AiProviderConfig::where('slug', $row['slug'])->first();
            if (! $p) {
                continue;
            }
            if (array_key_exists('default_model', $row) && $row['default_model'] !== null) {
                $p->default_model = $row['default_model'];
            }
            if (array_key_exists('base_url', $row) && $row['base_url'] !== null) {
                $p->base_url = $row['base_url'];
            }
            if (array_key_exists('enabled', $row) && $row['enabled'] !== null) {
                $p->enabled = (bool) $row['enabled'];
            }
            // Chave: só mexe se vier explícito. clear_key apaga; api_key não-vazio define.
            if (! empty($row['clear_key'])) {
                $p->api_key = null;
            } elseif (isset($row['api_key']) && trim($row['api_key']) !== '') {
                $p->api_key = trim($row['api_key']); // cast `encrypted` cuida de criptografar
            }
            $p->save();
        }

        if (! empty($data['default_provider'])) {
            SystemSetting::set('ai.default_provider', $data['default_provider']);
        }

        return $this->index();
    }

    /** POST /ai-credentials/{slug}/test — valida a credencial efetiva com uma chamada mínima ao provider. */
    public function test(string $slug): JsonResponse
    {
        if (! isset(self::KNOWN[$slug])) {
            return response()->json(['ok' => false, 'message' => 'Provedor desconhecido.'], 422);
        }
        $p = AiProviderConfig::where('slug', $slug)->first();
        $key = $p?->resolveApiKey();
        if (! $key) {
            return response()->json(['ok' => false, 'message' => 'Sem chave configurada para este provedor.'], 422);
        }
        $base = $p->base_url ?: self::KNOWN[$slug]['base_url'];
        $model = $p->default_model ?: self::KNOWN[$slug]['default_model'];

        try {
            if ($slug === 'anthropic') {
                $resp = Http::timeout(20)->withHeaders([
                    'x-api-key'         => $key,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])->post(rtrim($base, '/').'/messages', [
                    'model'      => $model,
                    'max_tokens' => 8,
                    'messages'   => [['role' => 'user', 'content' => 'ping']],
                ]);
            } else {
                $resp = Http::timeout(20)->withToken($key)->post(rtrim($base, '/').'/chat/completions', [
                    'model'      => $model,
                    'max_tokens' => 8,
                    'messages'   => [['role' => 'user', 'content' => 'ping']],
                ]);
            }

            if ($resp->successful()) {
                return response()->json(['ok' => true, 'message' => "Conexão OK com {$slug} ({$model}).", 'source' => $p->keySource()]);
            }
            $msg = (string) ($resp->json('error.message') ?? $resp->json('error.type') ?? ('HTTP '.$resp->status()));
            return response()->json(['ok' => false, 'message' => "Falha: {$msg}"], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Falha ao conectar: '.$e->getMessage()], 422);
        }
    }
}
