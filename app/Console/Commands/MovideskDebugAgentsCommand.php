<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class MovideskDebugAgentsCommand extends Command
{
    protected $signature   = 'movidesk:debug-agents {--top=3 : Quantos registros}';
    protected $description = 'Mostra o JSON bruto de personType=1 do /persons para debug';

    public function handle(): int
    {
        $token = config('services.movidesk.token');
        $top   = (int) $this->option('top');

        // Busca sem $select para ver TODOS os campos disponíveis
        $url = 'https://api.movidesk.com/public/v1/persons'
            . '?token=' . urlencode($token)
            . '&$top='  . ($top * 10); // busca mais para garantir que há tipo 1

        $this->info("GET {$url}");

        $response = Http::timeout(30)->get($url);
        $this->info("Status HTTP: {$response->status()}");

        $data = $response->json();
        if (empty($data)) {
            $this->warn('Resposta vazia.');
            $this->line($response->body());
            return self::FAILURE;
        }

        if (isset($data['id'])) $data = [$data];

        $type1 = array_filter($data, fn($p) => ($p['personType'] ?? 0) == 1);

        $this->info('Total na página: ' . count($data));
        $this->info('PersonType=1 na página: ' . count($type1));
        $this->line('');

        foreach (array_slice(array_values($type1), 0, $top) as $i => $p) {
            $this->line("── Agente #" . ($i + 1) . " ──────────────────────────────");
            $this->line(json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line('');
        }

        return self::SUCCESS;
    }
}
