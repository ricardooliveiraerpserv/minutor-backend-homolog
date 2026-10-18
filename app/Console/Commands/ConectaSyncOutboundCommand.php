<?php

namespace App\Console\Commands;

use App\Jobs\PushTicketToConectaJob;
use App\Models\ConectaTicketMigrationLog;
use App\Models\Customer;
use App\Models\HelpDeskTicket;
use App\Models\SystemSetting;
use App\Services\ConectaTicketPusher;
use Illuminate\Console\Command;

/**
 * Espelha na Conecta (ERPSERV→Conecta) os chamados/interações NOVOS de clientes migrados:
 * re-empurra os tickets cuja last_activity_at mudou desde o último push (sent_at no log).
 * O pusher já exclui o que veio da Conecta (source=conecta_sync) — anti-eco. Idempotente.
 * Gated por conecta_sync_enabled (default OFF). Ver [[project_minutor_conecta_client_migration]].
 */
class ConectaSyncOutboundCommand extends Command
{
    protected $signature = 'conecta:sync {--force : ignora o flag de habilitação}';

    protected $description = 'Espelha na Conecta chamados/interações novos de clientes migrados (ERPSERV→Conecta).';

    public function handle(ConectaTicketPusher $pusher): int
    {
        if (! $this->option('force') && ! (bool) SystemSetting::get('conecta_sync_enabled', false)) {
            return self::SUCCESS; // desabilitado
        }

        $cids = Customer::whereNotNull('migrated_to_conecta_at')->pluck('id');
        if ($cids->isEmpty()) {
            return self::SUCCESS;
        }

        $dispatched = 0;
        HelpDeskTicket::withoutCompanyScope()
            ->whereIn('customer_id', $cids)
            ->whereNotNull('last_activity_at')
            ->select('id', 'last_activity_at', 'source_system', 'external_ref')
            ->chunkById(500, function ($rows) use ($pusher, &$dispatched) {
                foreach ($rows as $t) {
                    [$ss, $ref] = $pusher->sourceRef($t);
                    $log = ConectaTicketMigrationLog::where('source_system', $ss)->where('external_ref', $ref)->first(['sent_at']);
                    // Precisa espelhar se nunca foi (sem log/sent_at) ou mudou desde o último push.
                    if (! $log || $log->sent_at === null || $t->last_activity_at->gt($log->sent_at)) {
                        PushTicketToConectaJob::dispatch((int) $t->id, $log ? 'legado' : 'novo');
                        $dispatched++;
                    }
                }
            });

        $this->info("conecta:sync → {$dispatched} chamado(s) enfileirado(s) p/ espelhar.");
        return self::SUCCESS;
    }
}
