<?php

namespace App\Jobs;

use App\Models\HelpDeskTicket;
use App\Services\ConectaTicketPusher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Enfileira o push da cópia de UM ticket p/ a Conecta (backfill do legado ou log de entrada de novo).
 * Re-tenta até $tries; o log (conecta_ticket_migration_log) registra o resultado a cada tentativa.
 */
class PushTicketToConectaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 180;

    public function __construct(public int $ticketId, public string $kind = 'legado') {}

    public function handle(ConectaTicketPusher $pusher): void
    {
        // withoutCompanyScope: no worker pode haver contexto de empresa (vazado de outro job) ≠ da
        // empresa do ticket → find() com CompanyScope retornaria null e o job sairia sem enviar nada.
        $ticket = HelpDeskTicket::withoutCompanyScope()->find($this->ticketId);
        if (! $ticket) {
            return; // ticket sumiu (ex.: excluído) → nada a enviar
        }
        $log = $pusher->push($ticket, $this->kind);
        if ($log->result === 'error') {
            // deixa o job falhar p/ re-tentar; o erro já está persistido no log.
            throw new \RuntimeException('Push p/ Conecta falhou (ticket ' . $this->ticketId . '): ' . $log->error_msg);
        }
    }
}
