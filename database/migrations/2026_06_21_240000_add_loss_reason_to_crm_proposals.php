<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V1.5 — Motivos de Perda na proposta (base p/ indicadores de conversão da P-E.2).
 * Reusa o catálogo crm_loss_reasons (mesmo das oportunidades).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_proposals', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_proposals', 'loss_reason_id')) $table->unsignedBigInteger('loss_reason_id')->nullable()->after('motivo_bloqueio');
            if (!Schema::hasColumn('crm_proposals', 'motivo_perda_obs')) $table->text('motivo_perda_obs')->nullable()->after('loss_reason_id');
            if (!Schema::hasColumn('crm_proposals', 'perdida_em')) $table->timestamp('perdida_em')->nullable()->after('motivo_perda_obs');
            if (!Schema::hasColumn('crm_proposals', 'perdida_por')) $table->unsignedBigInteger('perdida_por')->nullable()->after('perdida_em');
        });
        // Garante "Escopo" no catálogo (sugerido na V1.5) sem duplicar.
        if (Schema::hasTable('crm_loss_reasons') && !DB::table('crm_loss_reasons')->whereRaw('LOWER(name) = ?', ['escopo'])->exists()) {
            DB::table('crm_loss_reasons')->insert(['name' => 'Escopo', 'ordem' => 9, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('crm_proposals', function (Blueprint $table) {
            foreach (['loss_reason_id', 'motivo_perda_obs', 'perdida_em', 'perdida_por'] as $c) {
                if (Schema::hasColumn('crm_proposals', $c)) $table->dropColumn($c);
            }
        });
    }
};
