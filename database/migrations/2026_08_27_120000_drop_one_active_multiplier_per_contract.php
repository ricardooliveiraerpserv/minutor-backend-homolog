<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Multi-faixa por contrato (pedido do Ricardo 27/08).
 *
 * Antes: no máximo UMA regra ativa por contrato (índice único parcial
 * `contract_hour_multipliers_one_active_per_contract`). Agora um contrato pode ter
 * VÁRIAS faixas ativas, cada uma com seu próprio período [start_date, end_date] e sua
 * própria alíquota — desde que os períodos NÃO se sobreponham. A não-sobreposição é
 * garantida na validação do ContractHourMultiplierController (o Postgres não tem
 * exclusion constraint de daterange sem btree_gist habilitado, então a trava é na app).
 *
 * Mantém o índice de lookup (contract_id, active) — só derruba a UNIQUE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS contract_hour_multipliers_one_active_per_contract');
    }

    public function down(): void
    {
        // Recria a trava antiga (best-effort — só volta se não houver 2+ ativas no mesmo contrato).
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS contract_hour_multipliers_one_active_per_contract '
            . 'ON contract_hour_multipliers (contract_id) '
            . 'WHERE active AND deleted_at IS NULL'
        );
    }
};
