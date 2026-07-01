<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pré-requisitos de Assinatura Eletrônica (Clicksign) — consolidação arquitetural.
 *
 * Item 1: contract_document_id = Document OFICIAL do contrato (document_type=contrato).
 * Itens 2+3: rastreabilidade de origem + CONGELAMENTO documental — o contrato passa a
 * apontar EXPLICITAMENTE para a proposta + versão + documento (id/versão/hash) + memória
 * de cálculo que o originaram. Mesmo com V3/V4/V5 futuras, o contrato mantém a versão original.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('contracts', 'contract_document_id')) {
                $table->foreignId('contract_document_id')->nullable()->after('project_code_preview')
                    ->constrained('documents')->nullOnDelete();
            }
            if (!Schema::hasColumn('contracts', 'crm_proposal_id')) {
                $table->foreignId('crm_proposal_id')->nullable()->after('contract_document_id')
                    ->constrained('crm_proposals')->nullOnDelete();
            }
            if (!Schema::hasColumn('contracts', 'proposal_version')) {
                $table->unsignedInteger('proposal_version')->nullable()->after('crm_proposal_id');
            }
            if (!Schema::hasColumn('contracts', 'proposal_document_id')) {
                $table->foreignId('proposal_document_id')->nullable()->after('proposal_version')
                    ->constrained('documents')->nullOnDelete();
            }
            if (!Schema::hasColumn('contracts', 'proposal_document_version')) {
                $table->unsignedInteger('proposal_document_version')->nullable()->after('proposal_document_id');
            }
            if (!Schema::hasColumn('contracts', 'proposal_document_hash')) {
                $table->string('proposal_document_hash', 64)->nullable()->after('proposal_document_version');
            }
            if (!Schema::hasColumn('contracts', 'proposal_calc_snapshot')) {
                $table->json('proposal_calc_snapshot')->nullable()->after('proposal_document_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            foreach (['contract_document_id', 'crm_proposal_id', 'proposal_document_id'] as $fk) {
                if (Schema::hasColumn('contracts', $fk)) {
                    $table->dropConstrainedForeignId($fk);
                }
            }
            foreach (['proposal_version', 'proposal_document_version', 'proposal_document_hash', 'proposal_calc_snapshot'] as $col) {
                if (Schema::hasColumn('contracts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
