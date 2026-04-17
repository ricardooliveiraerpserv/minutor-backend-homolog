<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MovideskTicket;
use App\Models\User;
use Illuminate\Console\Command;

class MovideskPortalBackfillCommand extends Command
{
    protected $signature   = 'movidesk:portal-backfill';
    protected $description = 'Preenche user_id e customer_id nos tickets do portal a partir dos dados JSON existentes';

    public function handle(): int
    {
        $this->info('Backfill user_id (responsavel.email → users)...');
        $updatedUsers = 0;

        MovideskTicket::whereNull('user_id')
            ->whereNotNull('responsavel')
            ->orderBy('id')
            ->each(function (MovideskTicket $ticket) use (&$updatedUsers) {
                $email = $ticket->responsavel['email'] ?? null;
                if (!$email) return;
                $userId = User::where('email', $email)->value('id');
                if ($userId) {
                    $ticket->update(['user_id' => $userId]);
                    $updatedUsers++;
                }
            });

        $this->info("user_id preenchidos: {$updatedUsers}");

        $this->info('Backfill customer_id (solicitante.organization → customers)...');
        $updatedCustomers = 0;

        MovideskTicket::whereNull('customer_id')
            ->whereNotNull('solicitante')
            ->orderBy('id')
            ->each(function (MovideskTicket $ticket) use (&$updatedCustomers) {
                $orgName = $ticket->solicitante['organization'] ?? null;
                if (!$orgName) return;
                $customerId = Customer::where(function ($q) use ($orgName) {
                    $q->where('name', $orgName)->orWhere('company_name', $orgName);
                })->value('id');
                if ($customerId) {
                    $ticket->update(['customer_id' => $customerId]);
                    $updatedCustomers++;
                }
            });

        $this->info("customer_id preenchidos: {$updatedCustomers}");
        $this->info('Backfill concluído.');

        return self::SUCCESS;
    }
}
