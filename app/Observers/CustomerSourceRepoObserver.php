<?php

namespace App\Observers;

use App\Models\Customer;
use App\SourceCode\SourceRepoProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Regra: todo cliente ativo do Minutor tem repositório Git já conectado (erpserv-clientes),
 * com a chave "Contrato" (customers.has_contract) INATIVA — o provisionador não mexe nela.
 * Cobre qualquer caminho de criação via Eloquent (tela, importação Movidesk, CRM...).
 * Best-effort: nunca quebra o salvamento do cliente. Caminhos via SQL cru são cobertos
 * pelo agendamento diário de source-repos:backfill.
 */
class CustomerSourceRepoObserver
{
    public function created(Customer $customer): void
    {
        $this->provision($customer);
    }

    public function updated(Customer $customer): void
    {
        if ($customer->wasChanged('active') && $customer->active) {
            $this->provision($customer);
        }
    }

    private function provision(Customer $customer): void
    {
        DB::afterCommit(function () use ($customer) {
            try {
                $prov = app(SourceRepoProvisioner::class);
                if ($prov->shouldProvision($customer)) {
                    $prov->provisionFor($customer);
                }
            } catch (\Throwable $e) {
                Log::warning('source_repo.provision_failed', ['customer_id' => $customer->id, 'error' => $e->getMessage()]);
            }
        });
    }
}
