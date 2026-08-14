<?php

namespace App\SourceCode\Analyzer;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Provider de IA da documentação via Anthropic — API COMERCIAL, server-side (nunca Claude.ai).
 * Governança: sem Files API, sem Batch, sem prompt caching persistente. NUNCA loga o prompt/
 * resposta (payload integral) nem segredos — só metadados (modelo, status, tokens, ms).
 * O chamador é responsável por enviar apenas conteúdo estritamente necessário e SANITIZADO.
 */
class AnthropicProvider implements SourceDocAiProvider
{
    private const VERSION = '2023-06-01';

    public function isConfigured(): bool
    {
        return (bool) config('services.anthropic.api_key');
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return (string) config('services.source_doc_ai.model', config('services.anthropic.model', 'claude-sonnet-5'));
    }

    public function complete(string $system, string $user, array $opts = []): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('IA não configurada (ANTHROPIC_API_KEY ausente).');
        }
        $base = rtrim((string) config('services.anthropic.base_url', 'https://api.anthropic.com/v1'), '/');
        $model = $opts['model'] ?? $this->model();
        $t0 = microtime(true);

        $res = Http::timeout((int) config('services.source_doc_ai.timeout', 120))
            ->withHeaders([
                'x-api-key'         => (string) config('services.anthropic.api_key'),
                'anthropic-version' => self::VERSION,
                'content-type'      => 'application/json',
            ])
            ->post("{$base}/messages", [
                'model'       => $model,
                'max_tokens'  => (int) ($opts['max_tokens'] ?? config('services.source_doc_ai.max_tokens', 4096)),
                'temperature' => (float) ($opts['temperature'] ?? 0.1),
                'system'      => $system,
                'messages'    => [['role' => 'user', 'content' => $user]],
                // sem cache_control / sem batch / sem files — governança de retenção.
            ]);

        $ms = (int) ((microtime(true) - $t0) * 1000);
        if (!$res->successful()) {
            // Log SEM payload (só status). A mensagem de erro do Anthropic pode conter texto de billing,
            // mas nunca o nosso código — ainda assim não a propagamos para o usuário final.
            Log::warning('source_doc_ai.http_error', ['provider' => 'anthropic', 'model' => $model, 'status' => $res->status(), 'ms' => $ms]);
            throw new RuntimeException('Falha ao consultar a IA (HTTP ' . $res->status() . ').');
        }

        Log::info('source_doc_ai.ok', [
            'provider'      => 'anthropic',
            'model'         => $model,
            'ms'            => $ms,
            'input_tokens'  => $res->json('usage.input_tokens'),
            'output_tokens' => $res->json('usage.output_tokens'),
        ]);

        return [
            'text'  => (string) ($res->json('content.0.text') ?? ''),
            'usage' => (array) ($res->json('usage') ?? []),
            'stop'  => $res->json('stop_reason'),
        ];
    }
}
