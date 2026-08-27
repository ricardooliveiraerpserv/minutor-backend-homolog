<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha Conector — Connector-2 (observabilidade Protheus, READ-ONLY). Acrescenta:
 *  - colunas de FRESCOR do inventário em connector_environment_state (autoridade received_at,
 *    distinta da presença C-1). observed_json guarda o estado corrente observado.
 *  - connector_rpo_snapshots: HISTÓRICO de RPO (append SÓ em mudança de sha256).
 *  - connector_events: transições SIGNIFICATIVAS (família operacoes da timeline C1).
 * SEM tabela de comandos. Nenhum secret/path/INI/bytes de RPO cruza a fronteira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_environment_state', function (Blueprint $t) {
            if (! Schema::hasColumn('connector_environment_state', 'inventory_received_at')) {
                $t->timestamp('inventory_received_at')->nullable(); // AUTORIDADE de frescor do INVENTÁRIO (≠ presença)
            }
            if (! Schema::hasColumn('connector_environment_state', 'inventory_observed_at')) {
                $t->timestamp('inventory_observed_at')->nullable(); // diagnóstico (relógio do agente na coleta)
            }
            if (! Schema::hasColumn('connector_environment_state', 'observed_json')) {
                $t->jsonb('observed_json')->nullable(); // estado corrente OBSERVADO (appservers/rest/rpo/collect_error) — sem secret/path
            }
        });
        // Presença (last_seen_at) é INDEPENDENTE do inventário: se o inventário chegar antes do
        // heartbeat, a linha existe com last_seen_at NULL → presença = never_seen (não online por causa do inventário).
        try {
            DB::statement('ALTER TABLE connector_environment_state ALTER COLUMN last_seen_at DROP NOT NULL');
        } catch (\Throwable) {
        }

        if (! Schema::hasTable('connector_rpo_snapshots')) {
            Schema::create('connector_rpo_snapshots', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('environment_id');
                $t->uuid('appserver_ref');            // AppServer estável (não path/host)
                $t->string('rpo_hash', 64);           // sha256 do artefato = identidade
                $t->string('rpo_version', 60)->nullable();
                $t->unsignedBigInteger('size_bytes')->nullable();
                $t->timestamp('mtime')->nullable();
                $t->timestamp('observed_at');         // received_at do backend quando registrou a mudança
                $t->timestamps();
                $t->index(['environment_id', 'appserver_ref', 'observed_at']);
            });
        }

        if (! Schema::hasTable('connector_events')) {
            Schema::create('connector_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('environment_id');
                $t->uuid('appserver_ref')->nullable();
                // appserver_up|appserver_down|process_changed|version_changed|rpo_changed|rest_health_changed
                $t->string('event_type', 40);
                $t->string('outcome', 12)->default('info'); // ok|fail|info
                $t->string('detail', 200)->nullable();      // sanitizado (sem secret/path)
                $t->jsonb('meta')->nullable();              // sanitizado (from/to versão, rpo_hash curto, healthy...)
                $t->timestamp('occurred_at');               // received_at (autoridade)
                $t->timestamps();
                $t->index(['environment_id', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_events');
        Schema::dropIfExists('connector_rpo_snapshots');
        Schema::table('connector_environment_state', function (Blueprint $t) {
            foreach (['inventory_received_at', 'inventory_observed_at', 'observed_json'] as $c) {
                if (Schema::hasColumn('connector_environment_state', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
