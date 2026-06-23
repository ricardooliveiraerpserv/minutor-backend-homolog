<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desacoplamento Proposal-Centric (aditivo, NÃO-destrutivo).
 *
 * A Proposta (CrmProposal) passa a ser a entidade central do fluxo operacional:
 * liberação/checklist/bloqueio/auditoria + geração de Projeto vivem na proposta, sem depender
 * da entidade Contract. Vínculo jurídico = METADADOS (cliente + proposta), sem Umbrella Contract.
 *
 * Contrato Individual + Clicksign permanecem no código (arquivados/inativos) — NADA é dropado aqui.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_proposals', function (Blueprint $table) {
            // Liberação operacional (auditoria) — migra do Contract p/ a Proposta.
            if (!Schema::hasColumn('crm_proposals', 'liberado_por')) {
                $table->foreignId('liberado_por')->nullable()->after('status')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('crm_proposals', 'liberado_em')) {
                $table->timestamp('liberado_em')->nullable()->after('liberado_por');
            }
            if (!Schema::hasColumn('crm_proposals', 'liberacao_observacao')) {
                $table->text('liberacao_observacao')->nullable()->after('liberado_em');
            }
            // Bloqueio operacional (HOLD reversível — não altera o status).
            if (!Schema::hasColumn('crm_proposals', 'bloqueado_por')) {
                $table->foreignId('bloqueado_por')->nullable()->after('liberacao_observacao')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('crm_proposals', 'bloqueado_em')) {
                $table->timestamp('bloqueado_em')->nullable()->after('bloqueado_por');
            }
            if (!Schema::hasColumn('crm_proposals', 'motivo_bloqueio')) {
                $table->text('motivo_bloqueio')->nullable()->after('bloqueado_em');
            }
            // Projeto gerado DIRETO da proposta (sem Contract).
            if (!Schema::hasColumn('crm_proposals', 'project_id')) {
                $table->foreignId('project_id')->nullable()->after('motivo_bloqueio')->constrained('projects')->nullOnDelete();
            }
            // Vínculo jurídico como METADADO (qual contrato guarda-chuva ampara esta proposta).
            if (!Schema::hasColumn('crm_proposals', 'umbrella_ref')) {
                $table->string('umbrella_ref', 60)->nullable()->after('project_id');
            }
        });

        // Checklist de Liberação passa a poder pertencer à PROPOSTA (re-key aditivo).
        Schema::table('contract_release_checklist_items', function (Blueprint $table) {
            if (!Schema::hasColumn('contract_release_checklist_items', 'crm_proposal_id')) {
                $table->foreignId('crm_proposal_id')->nullable()->after('contract_id')->constrained('crm_proposals')->cascadeOnDelete();
            }
        });
        // contract_id deixa de ser obrigatório (itens agora podem ser da proposta).
        Schema::table('contract_release_checklist_items', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_id')->nullable()->change();
        });

        // Vínculo jurídico do cliente como METADADO (contrato guarda-chuva mestre).
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'umbrella_contract_numero')) {
                $table->string('umbrella_contract_numero', 60)->nullable();
            }
            if (!Schema::hasColumn('customers', 'umbrella_contract_assinatura')) {
                $table->date('umbrella_contract_assinatura')->nullable();
            }
            if (!Schema::hasColumn('customers', 'umbrella_contract_vigencia')) {
                $table->date('umbrella_contract_vigencia')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_proposals', function (Blueprint $table) {
            foreach (['liberado_por', 'bloqueado_por', 'project_id'] as $fk) {
                if (Schema::hasColumn('crm_proposals', $fk)) $table->dropConstrainedForeignId($fk);
            }
            foreach (['liberado_em', 'liberacao_observacao', 'bloqueado_em', 'motivo_bloqueio', 'umbrella_ref'] as $c) {
                if (Schema::hasColumn('crm_proposals', $c)) $table->dropColumn($c);
            }
        });
        Schema::table('contract_release_checklist_items', function (Blueprint $table) {
            if (Schema::hasColumn('contract_release_checklist_items', 'crm_proposal_id')) $table->dropConstrainedForeignId('crm_proposal_id');
        });
        Schema::table('customers', function (Blueprint $table) {
            foreach (['umbrella_contract_numero', 'umbrella_contract_assinatura', 'umbrella_contract_vigencia'] as $c) {
                if (Schema::hasColumn('customers', $c)) $table->dropColumn($c);
            }
        });
    }
};
