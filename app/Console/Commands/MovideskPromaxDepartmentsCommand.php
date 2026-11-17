<?php

namespace App\Console\Commands;

use App\Services\MovideskDepartmentSync;
use App\Services\MovideskPromaxCopyService;
use Illuminate\Console\Command;

/** Cruza os departamentos da Promax (Movidesk × Minutor). --dry-run só relata. */
class MovideskPromaxDepartmentsCommand extends Command
{
    protected $signature = 'movidesk:promax-departments {--dry-run}';
    protected $description = 'Cruza os departamentos dos clientes Promax entre Movidesk e Minutor (o Movidesk prevalece)';

    public function handle(MovideskDepartmentSync $sync): int
    {
        foreach (MovideskPromaxCopyService::customerIds() as $cid) {
            $st = $sync->sync((int) $cid, !$this->option('dry-run'));
            $this->line("cliente {$cid}: " . json_encode(array_diff_key($st, ['divergentes' => 1]), JSON_UNESCAPED_UNICODE));
            foreach ($st['divergentes'] ?? [] as $d) $this->line("  ajustado {$d['email']}: Minutor={$d['minutor']} / Movidesk={$d['movidesk']}");
        }
        return self::SUCCESS;
    }
}
