<?php

namespace App\Console\Commands;

use App\Models\MovideskTicket;
use App\Services\MovideskService;
use Illuminate\Console\Command;

class MovideskEnrichTicketOrgsCommand extends Command
{
    protected $signature   = 'movidesk:enrich-ticket-orgs';
    protected $description = 'Preenche solicitante.organization nos tickets via /persons (campo organizations[])';

    public function handle(MovideskService $service): int
    {
        $this->info('Buscando mapa email → organização do Movidesk /persons...');
        $map = $service->fetchPersonOrgMap();
        $this->info(count($map) . ' pessoas com organização encontradas.');

        if (empty($map)) {
            $this->warn('Nenhum mapa retornado. Verifique a API.');
            return self::FAILURE;
        }

        // Tickets com solicitante sem organização
        $tickets = MovideskTicket::whereNotNull('solicitante')
            ->whereRaw("(solicitante->>'organization') IS NULL OR (solicitante->>'organization') = ''")
            ->get(['id', 'solicitante']);

        $this->info($tickets->count() . ' tickets para enriquecer.');

        $updated = 0;
        foreach ($tickets as $ticket) {
            $sol   = $ticket->solicitante ?? [];
            $email = strtolower(trim($sol['email'] ?? ''));
            if (!$email || !isset($map[$email])) continue;

            $sol['organization'] = $map[$email];
            $ticket->solicitante = $sol;
            $ticket->save();
            $updated++;
        }

        $this->info("{$updated} tickets atualizados com organização.");
        return self::SUCCESS;
    }
}
