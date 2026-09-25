<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha Conector Protheus — Connector-0 (identidade + canal seguro). SOMENTE duas tabelas:
 * enrollment tokens (bootstrap uso-único) e agents (identidade Ed25519 por ambiente).
 * NÃO cria estado/heartbeat/comando (Connector-1+). Aditiva, idempotente, com down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('connector_enrollment_tokens')) {
            Schema::create('connector_enrollment_tokens', function (Blueprint $t) {
                $t->id();
                $t->string('token_hash', 64)->unique();       // sha256 do token; nunca o token em claro
                $t->unsignedBigInteger('customer_id');        // escopo
                $t->unsignedBigInteger('environment_id');     // escopo (1 ambiente)
                $t->timestamp('expires_at');
                $t->timestamp('consumed_at')->nullable();     // uso único
                $t->uuid('consumed_by_agent_id')->nullable(); // rastreabilidade
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index('environment_id');
                $t->index('expires_at');
            });
        }

        if (! Schema::hasTable('connector_agents')) {
            Schema::create('connector_agents', function (Blueprint $t) {
                $t->id();
                $t->uuid('agent_id')->unique();               // identificador público (header X-Agent-Id)
                $t->unsignedBigInteger('customer_id');        // escopo (autoridade server-side)
                $t->unsignedBigInteger('environment_id');     // escopo (1 ambiente)
                $t->text('public_key');                       // Ed25519 pública (Base64 dos 32 bytes canônicos)
                $t->string('public_key_fingerprint', 64);     // sha256 dos bytes canônicos da chave
                $t->string('agent_version', 40)->nullable();
                $t->timestamp('enrolled_at');
                $t->timestamp('revoked_at')->nullable();      // revogação (falha imediata; não apaga identidade)
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index('customer_id');
                $t->index('public_key_fingerprint');
                // last_seen_at / estado observado entram no Connector-1 (com /connector/heartbeat).
            });

            // Um agente ATIVO por ambiente (revogar libera o ambiente p/ re-enroll).
            try {
                DB::statement('CREATE UNIQUE INDEX connector_agents_active_env_uq ON connector_agents (environment_id) WHERE revoked_at IS NULL');
            } catch (\Throwable) {
                // idempotência defensiva (reexecução / driver sem partial index)
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_agents');
        Schema::dropIfExists('connector_enrollment_tokens');
    }
};
