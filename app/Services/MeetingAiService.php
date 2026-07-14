<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fase 4 — gera RESUMO + ATA de uma reunião a partir da transcrição, via Claude.
 * Reusa o mesmo padrão de chamada da OcrService (config('services.anthropic.*')).
 * Degrada com graça: sem API key ou em falha, retorna null (o fluxo manual segue valendo).
 */
class MeetingAiService
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const VERSION  = '2023-06-01';
    private const MAX_TRANSCRIPT_CHARS = 60000; // corta transcrições gigantes p/ caber no contexto/custo

    public function isConfigured(): bool
    {
        return (bool) config('services.anthropic.api_key');
    }

    /**
     * @return array{summary:string, ata:string}|null
     */
    public function generate(string $transcript, ?string $title = null): ?array
    {
        if (!$this->isConfigured()) {
            Log::warning('[MeetingAi] ANTHROPIC_API_KEY ausente — geração desligada');
            return null;
        }

        $transcript = trim($transcript);
        if ($transcript === '') {
            return null;
        }
        if (mb_strlen($transcript) > self::MAX_TRANSCRIPT_CHARS) {
            $transcript = mb_substr($transcript, 0, self::MAX_TRANSCRIPT_CHARS) . "\n[…transcrição truncada…]";
        }

        $sys = 'Você é um assistente que resume reuniões corporativas em português do Brasil. '
            . 'Receberá a transcrição de uma reunião e deve devolver EXCLUSIVAMENTE um JSON válido, sem markdown, '
            . 'com exatamente duas chaves: "summary" (um parágrafo objetivo com os principais pontos discutidos) e '
            . '"ata" (texto estruturado em linhas com decisões tomadas, responsáveis e próximos passos, uma por linha, '
            . 'prefixadas por "- "). Não invente informação que não esteja na transcrição. Se algo não ficou claro, omita.';

        $user = ($title ? "Reunião: {$title}\n\n" : '') . "Transcrição:\n" . $transcript;

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'x-api-key'         => config('services.anthropic.api_key'),
                    'anthropic-version' => self::VERSION,
                    'content-type'      => 'application/json',
                ])
                ->post(self::ENDPOINT, [
                    'model'      => config('services.anthropic.meetings_model'),
                    'max_tokens' => 2048,
                    'system'     => $sys,
                    'messages'   => [[
                        'role'    => 'user',
                        'content' => [['type' => 'text', 'text' => $user]],
                    ]],
                ]);

            if (!$response->successful()) {
                Log::warning('[MeetingAi] Anthropic erro', ['status' => $response->status(), 'body' => substr($response->body(), 0, 500)]);
                return null;
            }

            // modelos com extended thinking retornam um bloco "thinking" antes do "text";
            // pega o PRIMEIRO bloco de tipo text, não um índice fixo.
            $text = null;
            foreach ((array) $response->json('content', []) as $block) {
                if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $text = $block['text'];
                    break;
                }
            }
            if (!is_string($text)) {
                Log::warning('[MeetingAi] resposta sem bloco de texto', ['body' => substr($response->body(), 0, 300)]);
                return null;
            }

            return $this->parse($text);
        } catch (\Throwable $e) {
            Log::error('[MeetingAi] Exceção', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /** Extrai o JSON {summary, ata} da resposta, tolerando cercas ```json e texto ao redor. */
    private function parse(string $text): ?array
    {
        $text = trim($text);
        // remove cercas de código, se houver
        $text = preg_replace('/^```(?:json)?|```$/m', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $json = substr($text, $start, $end - $start + 1);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        $summary = trim((string) ($data['summary'] ?? ''));
        $ata     = trim((string) ($data['ata'] ?? ''));
        if ($summary === '' && $ata === '') {
            return null;
        }

        return ['summary' => $summary, 'ata' => $ata];
    }
}
