<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixMigrationsSequenceCommand extends Command
{
    protected $signature   = 'db:fix-sequence';
    protected $description = 'Reseta a sequência da tabela migrations no PostgreSQL';

    public function handle(): int
    {
        try {
            $driver = DB::getDriverName();

            if ($driver !== 'pgsql') {
                $this->info("Driver {$driver} não requer correção de sequência.");
                return Command::SUCCESS;
            }

            DB::statement("SELECT setval('migrations_id_seq', (SELECT MAX(id) FROM migrations) + 1)");
            $this->info('Sequência da tabela migrations corrigida.');
        } catch (\Throwable $e) {
            $this->warn('Não foi possível corrigir sequência: ' . $e->getMessage());
        }

        return Command::SUCCESS;
    }
}
