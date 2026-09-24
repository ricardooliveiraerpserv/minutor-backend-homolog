<?php

namespace App\Jobs;

use App\Models\HelpDeskTicket;
use App\Services\CompanyContext;
use App\Services\HelpDeskTriggerEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Roda o motor de GATILHOS do Help Desk (status/aceite/recusa/atribuição/etc.) na FILA,
 * pra não sobrecarregar o Azure/Graph com o e-mail síncrono a cada evento. Fixa o
 * CompanyContext na empresa do chamado (multi-empresa) — como um request faria. Payload
 * só ids + contexto pequeno. Com QUEUE_CONNECTION=sync roda inline (igual hoje).
 */
class ProcessHelpDeskTriggersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 180;

    /** @param array<string,mixed> $context */
    public function __construct(public string $event, public int $ticketId, public array $context = [])
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
            return;
        }
        // Multi-empresa: os gatilhos são por empresa → fixa o contexto do chamado
        // (o request não existe mais na fila). Sempre restaura no finally.
        $ctx = app(CompanyContext::class);
        $ctx->set($ticket->company_id);
        try {
            HelpDeskTriggerEngine::dispatch($this->event, $ticket, $this->context);
        } finally {
            $ctx->forget();
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning("HelpDesk: gatilhos '{$this->event}' (ticket {$this->ticketId}) esgotaram as tentativas: " . $e->getMessage());
    }
}
