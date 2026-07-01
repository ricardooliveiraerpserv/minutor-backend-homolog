<?php

namespace App\Console\Commands;

use App\Http\Controllers\RelatorioRentabilidadeController;
use App\Models\CrmAccountHealthSnapshot;
use App\Models\Customer;
use App\Services\AccountHealthService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Roadmap Fase 1 — gera o snapshot diário de Saúde da Conta (histórico de evolução).
 * Busca a margem de TODOS os clientes numa única chamada (Rentabilidade×Keruak) e
 * calcula a saúde de cada empresa. Idempotente por dia (1 snapshot/cliente/dia).
 */
class SnapshotSaudeContaCommand extends Command
{
    protected $signature = 'crm:snapshot-saude {--competencia=}';
    protected $description = 'Gera snapshots diários de Saúde da Conta dos clientes';

    public function handle(AccountHealthService $health): int
    {
        $competencia = $this->option('competencia') ?: now()->format('Y-m');

        // Margem por cliente — 1 chamada (degrada p/ vazio se Keruak indisponível).
        $margens = [];
        try {
            $rows = app(RelatorioRentabilidadeController::class)->clientes(new Request(), $competencia)->getData(true)['data']['rows'] ?? [];
            foreach ($rows as $r) {
                if (!empty($r['customer_id'])) $margens[$r['customer_id']] = (float) ($r['margem'] ?? 0);
            }
        } catch (\Throwable $e) {
            $this->warn('Rentabilidade/Keruak indisponível — snapshot sem margem.');
        }

        $hoje = now()->toDateString();
        $n = 0;
        Customer::whereNull('deleted_at')->chunkById(200, function ($lote) use ($health, $margens, $competencia, $hoje, &$n) {
            foreach ($lote as $c) {
                if (CrmAccountHealthSnapshot::where('customer_id', $c->id)->whereDate('created_at', $hoje)->exists()) continue;
                $r = $health->compute($c, $margens[$c->id] ?? null);
                CrmAccountHealthSnapshot::create([
                    'customer_id' => $c->id, 'score' => $r['score'], 'status' => $r['status'],
                    'motivos' => $r['motivos'], 'margem' => $margens[$c->id] ?? null,
                    'competencia' => now()->startOfMonth()->toDateString(), 'created_at' => now(),
                ]);
                $n++;
            }
        });

        $this->info("Snapshots de saúde gerados: {$n} (competência {$competencia}).");
        return self::SUCCESS;
    }
}
