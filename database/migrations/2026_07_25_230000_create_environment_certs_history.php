<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cofre de Ambientes (F1c) — Certificados + Histórico do ambiente.
 * Metadados do cert em CLARO (validade → alertas na Fase 2); a SENHA do PFX é
 * segredo (env_secrets), e o ARQUIVO .pfx é cifrado no CLIENT e sobe como anexo
 * .enc (via attachments) — servidor nunca vê a chave privada.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('env_certificates')) {
            Schema::create('env_certificates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('environment_id')->constrained('env_environments')->cascadeOnDelete();
                $table->string('name');                              // [CLARO]
                $table->string('type', 10)->default('A1');           // A1|A3
                $table->string('issuer')->nullable();
                $table->string('subject')->nullable();
                $table->string('thumbprint')->nullable();
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();                // [CLARO] p/ alerta de vencimento
                $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('pfx_pass_secret_id')->nullable()->constrained('env_secrets')->nullOnDelete(); // senha do PFX [BLOB]
                $table->boolean('critical')->default(false);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();
                $table->index('environment_id');
                $table->index('valid_to');
            });
        }

        // Histórico de eventos de NEGÓCIO por ambiente (≠ env_access_logs, que é acesso).
        if (! Schema::hasTable('env_history')) {
            Schema::create('env_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('environment_id')->constrained('env_environments')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('kind', 40);                          // credential|database|appserver|vpn|certificate|file
                $table->string('description');
                $table->jsonb('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index('environment_id');
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('env_history');
        Schema::dropIfExists('env_certificates');
    }
};
