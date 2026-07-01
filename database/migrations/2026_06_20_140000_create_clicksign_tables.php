<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4.1 — Fundação Clicksign (API v3, Envelopes). MODELAGEM, sem chamadas reais ainda.
 *
 * Contrato × Envelope = 1:N (histórico de reenvios/correções/aditivos/renovação);
 * apenas 1 envelope ATIVO por contrato (índice parcial WHERE is_active).
 * Envelope → Signers (N) → Requirements (N). Webhooks com idempotência por event_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('clicksign_envelopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->unsignedInteger('document_version')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('environment', 16)->default('sandbox'); // sandbox|production
            $table->string('clicksign_envelope_id')->nullable();
            $table->string('clicksign_document_id')->nullable();
            $table->string('status', 32)->default('draft'); // draft|running|finished|cancelled|refused|deadline
            $table->string('motivo_envio', 24)->default('inicial'); // inicial|reenvio|correcao|nova_versao|aditivo|renovacao
            $table->string('default_subject')->nullable();
            $table->string('locale', 8)->default('pt-BR');
            $table->timestamp('deadline_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('raw_meta')->nullable();
            $table->timestamps();
            $table->index(['contract_id', 'status']);
            $table->index('clicksign_envelope_id');
        });
        // Apenas 1 envelope ATIVO por contrato (Postgres índice único parcial).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX clicksign_one_active_per_contract ON clicksign_envelopes (contract_id) WHERE is_active');
        }

        Schema::create('clicksign_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('clicksign_envelopes')->cascadeOnDelete();
            $table->string('clicksign_signer_id')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('documentation', 20)->nullable(); // CPF
            $table->string('communicate_by', 16)->default('email'); // email|whatsapp|sms
            $table->unsignedSmallInteger('sign_order')->default(1);
            $table->text('sign_url')->nullable();
            $table->string('status', 16)->default('pending'); // pending|signed|refused
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();
            $table->index('envelope_id');
        });

        Schema::create('clicksign_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('clicksign_envelopes')->cascadeOnDelete();
            $table->foreignId('signer_id')->nullable()->constrained('clicksign_signers')->nullOnDelete();
            $table->string('clicksign_requirement_id')->nullable();
            $table->string('action', 24)->default('sign');  // sign|agree|provide_evidence
            $table->string('auth', 24)->default('email');   // email|icp_brasil|whatsapp
            $table->string('role', 32)->nullable();
            $table->timestamps();
            $table->index('envelope_id');
        });

        Schema::create('clicksign_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();   // idempotência
            $table->string('event_name', 64)->nullable();
            $table->string('clicksign_envelope_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index('clicksign_envelope_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clicksign_requirements');
        Schema::dropIfExists('clicksign_signers');
        Schema::dropIfExists('clicksign_webhook_events');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS clicksign_one_active_per_contract');
        }
        Schema::dropIfExists('clicksign_envelopes');
    }
};
