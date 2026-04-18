<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class MovideskDebugPersonsCommand extends Command
{
    protected $signature   = 'movidesk:debug-persons {--top=5 : Quantos registros mostrar}';
    protected $description = 'Mostra o JSON bruto dos primeiros registros de /persons do Movidesk';

    public function handle(): int
    {
        $token = config('services.movidesk.token');
        $top   = (int) $this->option('top');

        $url = 'https://api.movidesk.com/public/v1/persons'
            . '?token=' . urlencode($token)
            . '&$top='  . $top;

        $this->info("GET {$url}");
        $this->line('');

        $response = Http::timeout(30)->get($url);

        $this->info("Status: {$response->status()}");
        $this->line('');

        $data = $response->json();

        if (empty($data)) {
            $this->warn('Resposta vazia ou erro.');
            $this->line($response->body());
            return self::FAILURE;
        }

        if (isset($data['id'])) $data = [$data];

        $this->info(count($data) . ' registro(s) retornados. Exibindo campos-chave:');
        $this->line('');

        foreach ($data as $i => $p) {
            $this->line("── Registro #" . ($i + 1) . " ──────────────────────────────");
            $this->line("id:           " . ($p['id'] ?? '—'));
            $this->line("businessName: " . ($p['businessName'] ?? '—'));
            $this->line("personType:   " . ($p['personType'] ?? '—'));
            $this->line("cpfCnpj:      " . ($p['cpfCnpj'] ?? '—'));
            $this->line("isActive:     " . (isset($p['isActive']) ? ($p['isActive'] ? 'true' : 'false') : '—'));
            $this->line("Campos disponíveis: " . implode(', ', array_keys($p)));
            $this->line('');
        }

        return self::SUCCESS;
    }
}
