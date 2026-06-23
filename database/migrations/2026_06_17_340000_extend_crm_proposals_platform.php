<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1.2 — entidade Proposta integrada à Plataforma de Documentos + numeração única.
 * codigo (ESP012-26, reservado 1x), tipo, customer_id (numeração/consulta), calc_id (memória da versão),
 * document_id (PDF da versão corrente).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_proposals', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_proposals', 'codigo')) {
                $table->string('codigo', 40)->nullable()->after('id')->index();
            }
            if (!Schema::hasColumn('crm_proposals', 'tipo')) {
                $table->string('tipo', 24)->nullable()->after('codigo'); // bh_fixo|bh_mensal|on_demand|projeto_fechado
            }
            if (!Schema::hasColumn('crm_proposals', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('opportunity_id')->constrained('customers')->nullOnDelete();
            }
            if (!Schema::hasColumn('crm_proposals', 'calc_id')) {
                $table->foreignId('calc_id')->nullable()->after('memoria_calculo')->constrained('crm_proposal_calc')->nullOnDelete();
            }
            if (!Schema::hasColumn('crm_proposals', 'document_id')) {
                $table->foreignId('document_id')->nullable()->after('calc_id')->constrained('documents')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_proposals', function (Blueprint $table) {
            foreach (['document_id', 'calc_id', 'customer_id'] as $fk) {
                if (Schema::hasColumn('crm_proposals', $fk)) {
                    $table->dropConstrainedForeignId($fk);
                }
            }
            foreach (['tipo', 'codigo'] as $c) {
                if (Schema::hasColumn('crm_proposals', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
