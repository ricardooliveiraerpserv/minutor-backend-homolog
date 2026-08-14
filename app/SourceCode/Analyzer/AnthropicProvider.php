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
        // Fontes Protheus costumam ser Windows-1252/latin-1 → bytes inválidos como UTF-8 quebram
        // o JSON da API (HTTP 400). Normaliza para UTF-8 válido preservando acentos.
        $system = $this->toUtf8($system);
        $user = $this->toUtf8($user);
        $t0 = microtime(true);

        $res = Http::timeout((int) config('services.source_doc_ai.timeout', 120))
            ->withHeaders([
                'x-api-key'         => (string) config('services.anthropic.api_key'),
                'anthropic-version' => self::VERSION,
                'content-type'      => 'application/json',
            ])
            ->post("{$base}/messages", [
                'model'      => $model,
                'max_tokens' => (int) ($opts['max_tokens'] ?? config('services.source_doc_ai.max_tokens', 4096)),
                'system'     => $system,
                'messages'   => [['role' => 'user', 'content' => $user]],
                // sem 'temperature' (deprecado no Sonnet-5) · sem cache_control/batch/files — governança.
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

        // Sonnet-5 pode devolver um bloco "thinking" ANTES do "text" — pega o 1º bloco type=text
        // (não content[0], que pode ser thinking).
        $text = '';
        foreach ((array) $res->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text = (string) ($block['text'] ?? '');
                break;
            }
        }

        return [
            'text'  => $text,
            'usage' => (array) ($res->json('usage') ?? []),
            'stop'  => $res->json('stop_reason'),
        ];
    }

    /** Garante UTF-8 válido (fontes Protheus em Windows-1252 têm bytes inválidos como UTF-8). */
    private function toUtf8(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}
