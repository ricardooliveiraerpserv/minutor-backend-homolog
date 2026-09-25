<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Connector-5.2 — rpo_promote (SÓ activation_mode=hot). Aditivo: a operação de publicação é baseada em
 * TARGET (não um único appserver_ref), então:
 *  - connector_operations.appserver_ref passa a NULLABLE (rpo_promote guarda o alvo em rpo_target_id +
 *    payload no precondition_snapshot). A concorrência 1-op-por-ambiente (índice único parcial) cobre.
 *  - effect_started_at: 2º marcador (journal) DISTINTO de execution_committed — só diagnóstico/reconciliação,
 *    NUNCA base de retry.
 *  - rpo_target_id: alvo lógico (queryabilidade).
 *  - rpo_targets.last_successfully_published: publicação tecnicamente confirmada (≠ known_good).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('connector_operations')) {
            try {
                DB::statement('ALTER TABLE connector_operations ALTER COLUMN appserver_ref DROP NOT NULL');
            } catch (\Throwable) {
            }
            Schema::table('connector_operations', function (Blueprint $t) {
                if (! Schema::hasColumn('connector_operations', 'effect_started_at')) {
                    $t->timestamp('effect_started_at')->nullable(); // efeito potencialmente iniciado (journal)
                }
                if (! Schema::hasColumn('connector_operations', 'rpo_target_id')) {
                    $t->unsignedBigInteger('rpo_target_id')->nullable();
                }
            });
        }
        if (Schema::hasTable('rpo_targets') && ! Schema::hasColumn('rpo_targets', 'last_successfully_published')) {
            Schema::table('rpo_targets', function (Blueprint $t) {
                $t->jsonb('last_successfully_published')->nullable(); // {artifact_id, hash, at} — NÃO é known_good
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rpo_targets', 'last_successfully_published')) {
            Schema::table('rpo_targets', fn (Blueprint $t) => $t->dropColumn('last_successfully_published'));
        }
        Schema::table('connector_operations', function (Blueprint $t) {
            foreach (['effect_started_at', 'rpo_target_id'] as $c) {
                if (Schema::hasColumn('connector_operations', $c)) { $t->dropColumn($c); }
            }
        });
    }
};
