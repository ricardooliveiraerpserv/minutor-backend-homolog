<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — camada de Leads sobre a EMPRESA ÚNICA. Os dados de lead/qualificação ficam no
 * perfil 1:1 existente (customer_crm_profiles), sem criar tabela paralela de leads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_crm_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_crm_profiles', 'lead_source_id')) {
                $table->foreignId('lead_source_id')->nullable()->constrained('crm_lead_sources')->nullOnDelete();
            }
            if (!Schema::hasColumn('customer_crm_profiles', 'qualification_stage_id')) {
                $table->foreignId('qualification_stage_id')->nullable()->constrained('crm_pipeline_stages')->nullOnDelete();
            }
            if (!Schema::hasColumn('customer_crm_profiles', 'observacoes')) {
                $table->text('observacoes')->nullable();
            }
            if (!Schema::hasColumn('customer_crm_profiles', 'ultima_interacao_at')) {
                $table->timestamp('ultima_interacao_at')->nullable();
            }
            if (!Schema::hasColumn('customer_crm_profiles', 'proxima_acao_at')) {
                $table->date('proxima_acao_at')->nullable();
            }
            if (!Schema::hasColumn('customer_crm_profiles', 'proxima_acao')) {
                $table->string('proxima_acao', 200)->nullable();
            }
            if (!Schema::hasColumn('customer_crm_profiles', 'lead_created_at')) {
                $table->timestamp('lead_created_at')->nullable();
            }
            if (!Schema::hasColumn('customer_crm_profiles', 'qualified_at')) {
                $table->timestamp('qualified_at')->nullable();
            }
            if (!Schema::hasColumn('customer_crm_profiles', 'lost_at')) {
                $table->timestamp('lost_at')->nullable();
            }
            if (!Schema::hasColumn('customer_crm_profiles', 'lost_reason')) {
                $table->string('lost_reason', 200)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_crm_profiles', function (Blueprint $table) {
            foreach (['lead_source_id', 'qualification_stage_id', 'observacoes', 'ultima_interacao_at',
                'proxima_acao_at', 'proxima_acao', 'lead_created_at', 'qualified_at', 'lost_at', 'lost_reason'] as $col) {
                if (Schema::hasColumn('customer_crm_profiles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
