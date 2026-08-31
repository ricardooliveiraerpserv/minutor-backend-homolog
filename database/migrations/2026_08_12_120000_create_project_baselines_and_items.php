<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cronograma — EVM Fase 1: LINHA DE BASE (baseline) congelada do cronograma.
 *
 * O EVM exige uma referência de plano que NÃO muda quando o coordenador replaneja.
 * `project_baselines` = o "congelamento" (versionado; is_current alimenta o EVM);
 * `stage_baseline_items` = o snapshot imutável de datas/horas planejadas por etapa/atividade
 * no momento do congelamento. Sem isso, PV/SPI perdem sentido histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_baselines')) {
            Schema::create('project_baselines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->string('label', 80)->default('Linha de base');
                $table->timestamp('frozen_at');
                $table->foreignId('frozen_by')->nullable()->constrained('users')->nullOnDelete();
                $table->decimal('planned_hours_total', 12, 2)->default(0); // BAC em horas (Σ horas planejadas congeladas)
                $table->text('notes')->nullable();
                $table->boolean('is_current')->default(true);
                $table->timestamps();
                $table->index(['project_id', 'is_current']);
            });
        }

        if (! Schema::hasTable('stage_baseline_items')) {
            Schema::create('stage_baseline_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_baseline_id')->constrained('project_baselines')->cascadeOnDelete();
                // Referências ao vivo (nullable: a atividade/etapa pode ser apagada depois — o snapshot sobrevive).
                $table->foreignId('stage_id')->nullable()->constrained('project_stages')->nullOnDelete();
                $table->foreignId('stage_delivery_id')->nullable()->constrained('stage_deliveries')->nullOnDelete();
                $table->string('title', 255)->nullable();              // rótulo congelado (sobrevive a exclusões)
                $table->date('planned_start_at')->nullable();          // início planejado CONGELADO
                $table->date('planned_end_at')->nullable();            // fim planejado CONGELADO (plannedEndDate no congelamento)
                $table->decimal('planned_hours', 12, 2)->default(0);   // horas planejadas CONGELADAS (peso do EV)
                $table->timestamps();
                $table->index(['project_baseline_id', 'stage_id']);
                $table->index(['project_baseline_id', 'stage_delivery_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_baseline_items');
        Schema::dropIfExists('project_baselines');
    }
};
