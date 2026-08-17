<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campanha de Documentação Semântica Inicial — infraestrutura governada (baseline one-shot).
 * NÃO é o fluxo operacional futuro de GMUD. Orçamento GLOBAL com reserva atômica, dedup por blob
 * (fase 1 representantes → fase 2 réplicas), pause/resume/cancel, thresholds de parada, auditoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_semantic_campaign', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // draft, estimating, ready, running, paused, completed, completed_with_errors, cancelled
            $table->string('status', 30)->default('draft');
            $table->string('pause_reason', 40)->nullable(); // campaign_budget_reached, error_rate, provider_down, manual...

            // Orçamento GLOBAL + LEDGER de reserva atômica (ajuste #1).
            $table->decimal('budget_usd', 10, 4)->default(0);
            $table->decimal('safety_margin', 5, 4)->default(0.08); // 8%
            $table->decimal('estimated_total_usd', 10, 4)->default(0);
            $table->decimal('actual_cost_usd', 10, 4)->default(0);    // já efetivamente gasto
            $table->decimal('reserved_cost_usd', 10, 4)->default(0);  // jobs queued/running

            // Concorrência real (1 worker source-doc no homolog; escala depois de medir).
            $table->unsignedSmallInteger('max_concurrent')->default(1);
            $table->unsignedSmallInteger('batch_size')->default(50); // batch LÓGICO (preparação), não dispatch

            // Contadores.
            $table->unsignedInteger('total_candidates')->default(0); // source_docs alvo (fase1+fase2)
            $table->unsignedInteger('unique_blobs')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('reused')->default(0);
            $table->unsignedInteger('completed')->default(0);
            $table->unsignedInteger('partial')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('cost_limit_skipped')->default(0);
            $table->unsignedInteger('giants_excluded')->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('source_semantic_campaign_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('source_semantic_campaign')->cascadeOnDelete();
            $table->unsignedBigInteger('source_doc_id');
            $table->string('blob_sha', 64)->nullable();
            $table->string('band', 12); // pequeno|medio|grande|gigante
            $table->boolean('is_representative')->default(false); // 1º source_doc do blob (fase 1)
            $table->unsignedSmallInteger('phase')->default(1);    // 1=representante(IA) 2=réplica(reuso)
            // pending, running, reused, completed, partial, skipped, failed, excluded_giant
            $table->string('status', 20)->default('pending');
            $table->string('execution_status', 30)->nullable();   // do semantic_json (completed/partial/skipped_*)
            $table->string('documentary_completeness', 12)->nullable(); // completa|parcial
            $table->unsignedSmallInteger('funcoes_missing')->default(0);
            $table->decimal('estimated_cost_usd', 8, 4)->default(0);
            $table->decimal('cost_usd', 8, 4)->default(0);        // custo real
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error_kind', 40)->nullable();    // retryable/non_retryable + tipo
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status', 'phase']);
            $table->index(['campaign_id', 'blob_sha']);
            $table->unique(['campaign_id', 'source_doc_id']);
        });

        Schema::create('source_semantic_campaign_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('source_semantic_campaign')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event', 40); // created,started,paused,resumed,cancelled,budget_changed,auto_paused,completed
            $table->jsonb('meta')->nullable(); // sanitizado (sem código/prompt/secret)
            $table->timestamp('created_at')->nullable();

            $table->index(['campaign_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_semantic_campaign_events');
        Schema::dropIfExists('source_semantic_campaign_items');
        Schema::dropIfExists('source_semantic_campaign');
    }
};
