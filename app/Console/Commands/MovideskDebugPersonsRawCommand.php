<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class MovideskDebugPersonsRawCommand extends Command
{
    protected $signature   = 'movidesk:debug-persons-raw {--top=1}';
    protected $description = 'Mostra JSON bruto de /persons para ver campos de organização';

    public function handle(): int
    {
        $token = config('services.movidesk.token');
        $top   = (int) $this->option('top');

        $url = 'https://api.movidesk.com/public/v1/persons'
            . '?token=' . urlencode($token)
            . '&$top='  . $top;

        $this->info("GET $url");
        $response = Http::timeout(30)->get($url);
        $this->info('Status: ' . $response->status());

        $data = $response->json();
        if (isset($data['id'])) $data = [$data];

        foreach (array_slice((array) $data, 0, $top) as $i => $p) {
            $this->line("── Pessoa #" . ($i + 1) . " ──");
            $this->line(json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
