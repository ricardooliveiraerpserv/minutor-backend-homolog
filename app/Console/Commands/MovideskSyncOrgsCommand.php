<?php

namespace App\Console\Commands;

use App\Models\MovideskTicket;
use App\Models\Customer;
use App\Services\MovideskService;
use Illuminate\Console\Command;

class MovideskSyncOrgsCommand extends Command
{
    protected $signature   = 'movidesk:sync-orgs';
    protected $description = 'Busca organizações do Movidesk via /persons e atualiza cpf_cnpj + customer_id nos tickets';

    public function handle(MovideskService $service): int
    {
        $this->info('Buscando organizações no Movidesk...');
        $orgs = $service->fetchOrganizations();
        $this->info(count($orgs) . ' organizações encontradas.');

        if (empty($orgs)) {
            $this->warn('Nenhuma organização retornada. Verifique o token e o endpoint /persons.');
            return self::FAILURE;
        }

        // Mostra tabela das orgs com CNPJ
        $this->table(
            ['Organização', 'CNPJ'],
            collect($orgs)->map(fn($o) => [mb_substr($o['name'], 0, 40), $o['cpfCnpj'] ?: '(vazio)'])->values()->toArray()
        );

        $this->info('Atualizando cpf_cnpj nos tickets...');
        $updated = 0;

        MovideskTicket::whereNotNull('solicitante')->orderBy('id')->each(function (MovideskTicket $ticket) use ($orgs, &$updated) {
            $orgName = trim($ticket->solicitante['organization'] ?? '');
            if (!$orgName) return;

            $key  = strtolower($orgName);
            $org  = $orgs[$key] ?? null;
            $cnpj = $org['cpfCnpj'] ?? null;

            if (!$cnpj) return;

            $solicitante              = $ticket->solicitante;
            $solicitante['cpf_cnpj']  = $cnpj;
            $ticket->solicitante      = $solicitante;

            // Resolve customer_id pelo CNPJ
            if (!$ticket->customer_id) {
                $customerId = Customer::where('cgc', $cnpj)->value('id');
                if ($customerId) $ticket->customer_id = $customerId;
            }

            $ticket->save();
            $updated++;
        });

        $this->info("{$updated} tickets atualizados com CNPJ.");
        return self::SUCCESS;
    }
}
