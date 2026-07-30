<?php

namespace App\Jobs;

use App\Models\HelpDeskTicket;
use App\Services\CompanyContext;
use App\Services\HelpDeskReplyMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fila dos avisos DETERMINÍSTICOS de RECUSA da solução (equipe + cliente). Não depende de
 * gatilho configurado — é o "retorno" garantido quando o cliente recusa pelo link do e-mail.
 *
 * Seguro/progressivo: com QUEUE_CONNECTION=sync roda INLINE (igual hoje). Com database + worker,
 * vira assíncrono + throttled (mesmo limite helpdesk-email) e com RETRY/backoff em 429 do Azure.
 * Fixa o CompanyContext do chamado (multi-empresa) porque o job pode rodar fora do request.
 */
class SendHelpDeskRejectionEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 180;

    public function __construct(public int $ticketId, public string $reason, public ?int $commentId = null)
    {
    }

    public function backoff(): array
    {
        return [15, 30, 60, 120, 300];
    }

    public function middleware(): array
    {
        return (($this->connection ?? config('queue.default')) === 'sync') ? [] : [new RateLimited('helpdesk-email')];
    }

    public function handle(): void
    {
        $ticket = HelpDeskTicket::find($this->ticketId);
        if (!$ticket) {
            return; // apagado no meio → nada a enviar
        }

        $ctx = app(CompanyContext::class);
        $ctx->set($ticket->company_id);
        try {
            HelpDeskReplyMailer::sendRejectionNotices($ticket, $this->reason, $this->commentId);
        } finally {
            $ctx->forget();
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning("HelpDesk: avisos de recusa (ticket {$this->ticketId}) esgotaram as tentativas: " . $e->getMessage());
    }
}
