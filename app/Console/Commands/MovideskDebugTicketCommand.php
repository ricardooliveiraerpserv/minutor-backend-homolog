<?php

namespace App\Console\Commands;

use App\Models\MovideskTicket;
use Illuminate\Console\Command;

class MovideskDebugTicketCommand extends Command
{
    protected $signature   = 'movidesk:debug-ticket {id}';
    protected $description = 'Mostra campos solicitante/responsavel de um ticket específico';

    public function handle(): int
    {
        $ticket = MovideskTicket::where('ticket_id', $this->argument('id'))->first();
        if (!$ticket) {
            $this->error('Ticket não encontrado.');
            return self::FAILURE;
        }

        $this->line('ticket_id:    ' . $ticket->ticket_id);
        $this->line('customer_id:  ' . ($ticket->customer_id ?? 'null'));
        $this->line('owner_email:  ' . ($ticket->owner_email ?? 'null'));
        $this->line('');
        $this->line('solicitante:');
        $this->line(json_encode($ticket->solicitante, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
