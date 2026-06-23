<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * CRM — status do ciclo comercial na PRÓPRIA empresa (customers), sem segunda tabela de empresa.
 * lead | prospect | cliente | contrato_ativo | em_renovacao | inativo. `active` (operacional) intacto.
 * Backfill: quem já tem projeto vira 'contrato_ativo'; demais ativos 'cliente'; inativos 'inativo'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customers', 'crm_status')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('crm_status', 20)->default('lead')->after('active');
            });
        }

        // Backfill conservador (idempotente o suficiente p/ rodar 1x).
        DB::statement("UPDATE customers SET crm_status = 'inativo' WHERE active = false");
        DB::statement("UPDATE customers SET crm_status = 'cliente' WHERE active = true");
        DB::statement("UPDATE customers SET crm_status = 'contrato_ativo'
            WHERE id IN (SELECT DISTINCT customer_id FROM projects WHERE customer_id IS NOT NULL AND deleted_at IS NULL)
            AND crm_status = 'cliente'");
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'crm_status')) {
            Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('crm_status'));
        }
    }
};
