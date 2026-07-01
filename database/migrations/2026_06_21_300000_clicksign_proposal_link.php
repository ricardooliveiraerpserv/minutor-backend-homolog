<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-E.2.0 — Assinatura da PROPOSTA via Clicksign (reusa a infra de contratos).
 * Vincula envelope↔proposta e signer↔participante; contract_id passa a ser opcional.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('clicksign_envelopes', function (Blueprint $table) {
            if (!Schema::hasColumn('clicksign_envelopes', 'crm_proposal_id')) $table->unsignedBigInteger('crm_proposal_id')->nullable()->after('contract_id');
        });
        // contract_id agora é opcional (envelope pode ser de proposta).
        try { DB::statement('ALTER TABLE clicksign_envelopes ALTER COLUMN contract_id DROP NOT NULL'); } catch (\Throwable $e) { /* já nullable */ }
        Schema::table('clicksign_signers', function (Blueprint $table) {
            if (!Schema::hasColumn('clicksign_signers', 'crm_proposal_participant_id')) $table->unsignedBigInteger('crm_proposal_participant_id')->nullable()->after('envelope_id');
        });
    }

    public function down(): void
    {
        Schema::table('clicksign_envelopes', function (Blueprint $table) {
            if (Schema::hasColumn('clicksign_envelopes', 'crm_proposal_id')) $table->dropColumn('crm_proposal_id');
        });
        Schema::table('clicksign_signers', function (Blueprint $table) {
            if (Schema::hasColumn('clicksign_signers', 'crm_proposal_participant_id')) $table->dropColumn('crm_proposal_participant_id');
        });
    }
};
