<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo CRM ↔ Investimento Comercial: liga o lead (customer com crm_status='lead')
 * ao seu "lead-projeto" na árvore de Investimento Comercial da ERPSERV, onde os
 * apontamentos de prospecção são lançados (custo de aquisição).
 * Doc: docs/crm-lead-investimento-integracao.md
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_crm_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_crm_profiles', 'investimento_project_id')) {
                $table->foreignId('investimento_project_id')
                    ->nullable()
                    ->after('lead_source_id')
                    ->constrained('projects')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_crm_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('customer_crm_profiles', 'investimento_project_id')) {
                $table->dropConstrainedForeignId('investimento_project_id');
            }
        });
    }
};
