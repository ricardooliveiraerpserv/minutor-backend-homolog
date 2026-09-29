<?php

namespace App\Jobs;

use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Models\HelpDeskTicketEvent;
use App\Services\HelpDeskReplyMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fila de disparo do e-mail de RESPOSTA ao cliente (uma interação = um e-mail).
 * Objetivo: não sobrecarregar o Azure/Graph (rate limit) quando há muitas interações.
 *
 * Seguro/progressivo: com QUEUE_CONNECTION=sync roda INLINE (igual hoje). Quando a
 * infra ligar QUEUE_CONNECTION=database + subir um worker, vira assíncrono + throttled,
 * com RETRY/backoff em caso de 429 do Azure. Payload minúsculo (só ids) — reconstrói
 * o e-mail no processamento (anexos vêm do Supabase), sem inchar a tabela jobs.
 */
class SendHelpDeskEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 180;

    public function __construct(public int $ticketId, public int $commentId, public array $extraCc = [], public bool $isSolutionUpdate = false)
    {
    }

    /** Backoff progressivo entre tentativas (ex.: 429/limite do Azure). */
    public function backoff(): array
    {
        return [15, 30, 60, 120, 300];
    }

    /** Throttle só quando há fila de verdade (sync roda inline sem throttle). */
    public function middleware(): array
    {
        return (($this->connection ?? config('queue.default')) === 'sync') ? [] : [new RateLimited('helpdesk-email')];
    }

    public function handle(): void
    {
        $ticket  = HelpDeskTicket::find($this->ticketId);
        $comment = HelpDeskTicketComment::find($this->commentId);
        if (!$ticket || !$comment) {
            return; // apagados no meio → nada a enviar
        }

        [$sent, $reason] = HelpDeskReplyMailer::sendPublicComment($ticket, $comment, $this->extraCc, $this->isSolutionUpdate);

        if ($sent) {
            HelpDeskTicketEvent::log($ticket->id, 'email_sent', ['meta' => ['comment_id' => $comment->id]]);
            return;
        }
        // 'skip:...' = não era caso de envio (ex.: nota interna, sem destinatário) → não retenta.
        if (is_string($reason) && str_starts_with($reason, 'skip:')) {
            return;
        }
        // Falha real (ex.: 429/limite do Azure) → lança p/ o backoff/retry atuar.
        throw new \RuntimeException('HelpDesk: e-mail de resposta falhou: ' . (string) $reason);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning("HelpDesk: e-mail (ticket {$this->ticketId}/comment {$this->commentId}) esgotou as tentativas: " . $e->getMessage());
    }
}
