<?php

namespace App\Console\Commands;

use App\Services\MovideskHelpDeskImporter;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Importa (ENTRADA) os chamados Promax do Movidesk para o Help Desk do Minutor.
 * SOMENTE LEITURA no Movidesk — nunca escreve/interage lá.
 *
 *   php artisan movidesk:hd-import                 # respeita o flag movidesk_hd_import_enabled
 *   php artisan movidesk:hd-import --force         # roda mesmo desabilitado (teste)
 *   php artisan movidesk:hd-import --force --since="2026-09-01"
 */
class MovideskHelpDeskImportCommand extends Command
{
    protected $signature = 'movidesk:hd-import {--force : Ignora o flag de habilitação} {--since= : Data/hora mínima (ISO ou Y-m-d) para varrer} {--ticket= : Importa APENAS este id de ticket do Movidesk (validação, sem filtros)}';
    protected $description = 'Espelha os chamados Promax do Movidesk no Help Desk (entrada; somente leitura no Movidesk)';

    public function handle(MovideskHelpDeskImporter $importer): int
    {
        if ($this->option('ticket')) {
            $r = $importer->importOne((int) $this->option('ticket'));
            if (!$r['ticket_id']) { $this->error('Ticket não encontrado no Movidesk.'); return self::FAILURE; }
            $this->info(sprintf('Ticket Movidesk %s → HD %s (%s) | interações novas: %d',
                $this->option('ticket'), $r['hd_number'] ?? '?', $r['created'] ? 'CRIADO' : 'atualizado', $r['comments']));
            return self::SUCCESS;
        }

        $since = null;
        if ($this->option('since')) {
            try { $since = Carbon::parse($this->option('since')); }
            catch (\Throwable $e) { $this->error('--since inválido: ' . $e->getMessage()); return self::FAILURE; }
        }

        $stats = $importer->run((bool) $this->option('force'), $since);

        if (($stats['skipped'] ?? 0) === -1) {
            $this->warn('Import desabilitado (SystemSetting movidesk_hd_import_enabled=false). Use --force para testar.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Movidesk HD import — desde %s | varridos %d, novos %d, atualizados %d, interações %d, ignorados %d',
            $stats['since'] ?? '?', $stats['scanned'], $stats['imported'], $stats['updated'], $stats['comments'], max(0, $stats['skipped'])
        ));
        return self::SUCCESS;
    }
}
