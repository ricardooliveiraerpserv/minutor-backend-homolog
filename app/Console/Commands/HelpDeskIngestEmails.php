<?php

namespace App\Console\Commands;

use App\Services\HelpDeskEmailIngestor;
use Illuminate\Console\Command;

/** Ingestão de e-mails → chamados do Help Desk (Graph/Mail.Read). Agendado a cada 5 min. */
class HelpDeskIngestEmails extends Command
{
    protected $signature = 'help-desk:ingest-emails {--account= : ID de uma conta específica} {--limit=25 : Máx. de mensagens por conta}';
    protected $description = 'Lê a inbox das contas de e-mail e abre/atualiza chamados do Help Desk';

    public function handle(HelpDeskEmailIngestor $ingestor): int
    {
        $accountId = $this->option('account') ? (int) $this->option('account') : null;
        $limit     = max(1, (int) $this->option('limit'));

        $r = $ingestor->run($accountId, $limit);

        foreach ($r['details'] as $line) {
            $this->line('  ' . $line);
        }
        $this->info(sprintf(
            'Ingestão: %d conta(s), %d lidos → %d chamado(s), %d resposta(s), %d ignorado(s), %d erro(s).',
            $r['accounts'], $r['fetched'], $r['tickets'], $r['comments'], $r['ignored'], $r['errors']
        ));

        return self::SUCCESS;
    }
}
