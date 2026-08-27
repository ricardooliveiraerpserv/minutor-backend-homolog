<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha Conector — Connector-1 (presença/heartbeat). Espelho OBSERVADO do ambiente (1 linha/ambiente).
 * SÓ presença + saúde do canal — NADA de AppServer/REST/RPO/processo (Connector-2+).
 *
 * Autoridade de presença = last_seen_at (= received_at do backend), que SEMPRE avança em heartbeat
 * válido e NUNCA regride por observed_at. last_observed_at é diagnóstico monotônico. O STATUS
 * (never_seen/online/stale/offline/degraded) é DERIVADO na leitura — não é coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('connector_environment_state')) {
            return;
        }
        Schema::create('connector_environment_state', function (Blueprint $t) {
            $t->unsignedBigInteger('environment_id')->primary();   // 1 linha por ambiente
            $t->uuid('agent_id');                                  // quem reportou (muda no re-enroll)
            $t->timestamp('last_seen_at');                         // AUTORIDADE de presença (received_at)
            $t->timestamp('last_observed_at')->nullable();         // diagnóstico monotônico (relógio do agente)
            $t->integer('clock_offset_s')->nullable();             // received_at − observed_at
            $t->unsignedInteger('agent_uptime_s')->nullable();
            $t->string('agent_reported_status', 16)->nullable();   // auto-relato (NÃO autoridade)
            $t->text('last_error')->nullable();                    // sanitizado (≤200, sem secret)
            $t->timestamps();
            $t->index('agent_id');
            $t->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_environment_state');
    }
};
