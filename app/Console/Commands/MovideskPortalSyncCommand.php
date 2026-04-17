<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MovideskTicket;
use App\Models\User;
use App\Services\MovideskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MovideskPortalSyncCommand extends Command
{
    protected $signature   = 'movidesk:portal-sync {--backfill-only : Apenas reprocessa vínculos sem chamar a API}';
    protected $description = 'Sincroniza tickets do Movidesk para o Portal de Sustentação';

    public function handle(MovideskService $service): int
    {
        $backfillOnly = $this->option('backfill-only');

        if (!$backfillOnly) {
            $this->info('[MOVIDESK PORTAL] Iniciando sync da API...');
            Log::info('[MOVIDESK PORTAL] Iniciando sync');

            $tickets = $service->fetchPortalTickets();
            $count   = count($tickets);
            $this->info("[MOVIDESK PORTAL] {$count} tickets encontrados — salvando...");

            foreach ($tickets as $ticket) {
                $service->saveTicketForPortal($ticket);
            }

            $this->info("[MOVIDESK PORTAL] API sync concluído: {$count} tickets processados");
            Log::info('[MOVIDESK PORTAL] API sync concluído', ['count' => $count]);
        }

        // Backfill: resolve user_id e customer_id a partir dos JSON já armazenados no banco
        $this->backfillRelations();

        return self::SUCCESS;
    }

    private function backfillRelations(): void
    {
        $this->info('[MOVIDESK PORTAL] Backfill de user_id a partir de responsavel.email...');

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

        $this->info("[MOVIDESK PORTAL] Backfill user_id: {$updatedUsers} tickets vinculados");

        $this->info('[MOVIDESK PORTAL] Backfill de customer_id a partir de solicitante.organization...');

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

        $this->info("[MOVIDESK PORTAL] Backfill customer_id: {$updatedCustomers} tickets vinculados");

        Log::info('[MOVIDESK PORTAL] Backfill concluído', [
            'user_id_updated'     => $updatedUsers ?? 0,
            'customer_id_updated' => $updatedCustomers ?? 0,
        ]);
    }
}
