<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MovideskOrganization;
use App\Models\MovideskTicket;
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

        $this->table(
            ['Organização', 'CNPJ'],
            collect($orgs)->map(fn($o) => [mb_substr($o['name'], 0, 40), $o['cpfCnpj'] ?: '(vazio)'])->values()->toArray()
        );

        // Salva/atualiza tabela movidesk_organizations
        $this->info('Sincronizando tabela de organizações...');
        $customersByCnpj = Customer::whereNotNull('cgc')
            ->get()
            ->keyBy(fn($c) => preg_replace('/[^0-9]/', '', $c->cgc));

        foreach ($orgs as $org) {
            $cnpj       = $org['cpfCnpj'] ?? null;
            $customerId = null;

            if ($cnpj) {
                $cnpjNorm   = preg_replace('/[^0-9]/', '', $cnpj);
                $customerId = $customersByCnpj[$cnpjNorm]->id ?? null;

                if (!$customerId) {
                    $customerId = Customer::where('name', $org['name'])
                        ->orWhere('company_name', $org['name'])
                        ->value('id');
                }
            }

            MovideskOrganization::updateOrCreate(
                ['movidesk_id' => (string) $org['id']],
                ['name' => $org['name'], 'cnpj' => $cnpj ?: null, 'customer_id' => $customerId]
            );
        }

        // Atualiza cpf_cnpj e customer_id nos tickets
        $this->info('Atualizando tickets...');
        $updated = 0;

        MovideskTicket::whereNotNull('solicitante')->orderBy('id')->each(function (MovideskTicket $ticket) use ($orgs, &$updated) {
            $orgName = trim($ticket->solicitante['organization'] ?? '');
            if (!$orgName) return;

            $key  = strtolower($orgName);
            $org  = $orgs[$key] ?? null;
            $cnpj = $org['cpfCnpj'] ?? null;

            $changed = false;

            if ($cnpj) {
                $solicitante             = $ticket->solicitante;
                $solicitante['cpf_cnpj'] = $cnpj;
                $ticket->solicitante     = $solicitante;
                $changed = true;

                if (!$ticket->customer_id) {
                    $customerId = Customer::where('cgc', $cnpj)->value('id');
                    if ($customerId) {
                        $ticket->customer_id = $customerId;
                    }
                }
            }

            if ($changed) {
                $ticket->save();
                $updated++;
            }
        });

        $this->info("{$updated} tickets atualizados com CNPJ.");
        return self::SUCCESS;
    }
}
