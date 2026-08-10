<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reconecta projetos à sua requisição de origem (projects.contract_request_id) via a cadeia
 * requisição → contrato → projeto. Recupera o histórico de Comentários do cliente que ficou
 * órfão quando o projeto foi gerado pelo Kanban de Contratos (que não gravava o vínculo).
 * Idempotente: só preenche onde está null e confere o cliente por segurança.
 */
return new class extends Migration
{
    public function up(): void
    {
        $requests = DB::table('contract_requests')
            ->select('id', 'customer_id', 'contract_id', 'linked_contract_id')
            ->get();

        foreach ($requests as $r) {
            $contractId = $r->contract_id ?: $r->linked_contract_id;
            if (! $contractId) {
                continue;
            }
            $projectId = DB::table('contracts')->where('id', $contractId)->value('project_id');
            if (! $projectId) {
                continue;
            }
            // Guard: cliente do projeto tem que bater com o da requisição.
            DB::table('projects')
                ->where('id', $projectId)
                ->whereNull('contract_request_id')
                ->where('customer_id', $r->customer_id)
                ->update(['contract_request_id' => $r->id]);
        }
    }

    public function down(): void
    {
        // Não reverte: seria destruir o vínculo recuperado.
    }
};
