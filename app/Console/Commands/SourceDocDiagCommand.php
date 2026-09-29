<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\SourceCode\SourceDocService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/** Diagnóstico da IA de documentação: config + chamada real. Grava em system_settings 'diag_anthropic'. */
class SourceDocDiagCommand extends Command
{
    protected $signature = 'source-doc:diag';
    protected $description = 'Diagnostica a integração de IA (Anthropic) da documentação de fonte.';

    public function handle(): int
    {
        $k = (string) config('services.anthropic.api_key');
        $model = (string) config('services.anthropic.model');
        $base = (string) config('services.anthropic.base_url', 'https://api.anthropic.com/v1');
        $out = [
            'key_set'      => $k !== '',
            'key_len'      => strlen($k),
            'key_prefix'   => $k !== '' ? substr($k, 0, 7) : '',
            'model'        => $model,
            'base_url'     => $base,
            'aiConfigured' => app(SourceDocService::class)->aiConfigured(),
        ];

        if ($k !== '') {
            try {
                $r = Http::timeout(40)->withHeaders([
                    'x-api-key'         => $k,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])->post(rtrim($base, '/') . '/messages', [
                    'model'      => $model,
                    'max_tokens' => 40,
                    'messages'   => [['role' => 'user', 'content' => 'Responda só: OK']],
                ]);
                $out['http_status'] = $r->status();
                $out['http_body'] = mb_substr($r->body(), 0, 600);
            } catch (\Throwable $e) {
                $out['exception'] = $e->getMessage();
            }
        }

        SystemSetting::set('diag_anthropic', json_encode($out, JSON_UNESCAPED_UNICODE), 'string', 'diag');
        $this->info(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
