<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resultados-chave (KRs) de um objetivo. direction e aggregation definem como o
 * progresso é calculado (nunca uma fórmula única). data_source_type prepara as
 * fontes automáticas futuras (Fase 4) — na v1 todos são 'manual'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('okr_key_results')) {
            Schema::create('okr_key_results', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $t->foreignId('okr_objective_id')->constrained('okr_objectives')->cascadeOnDelete();
                $t->string('name', 255);
                $t->text('description')->nullable();
                $t->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
                // quantidade | percentual | reais | horas | dias | indice | custom
                $t->string('unit', 20)->default('quantidade');
                $t->string('unit_label', 50)->nullable()->comment('Rótulo da unidade quando unit=custom');
                // aumentar | reduzir | manter_faixa
                $t->string('direction', 20)->default('aumentar');
                // acumulativo | media | posicao_fechamento
                $t->string('aggregation', 30)->default('acumulativo');
                $t->decimal('baseline_value', 16, 4)->nullable()->comment('Valor inicial');
                $t->decimal('target_value', 16, 4)->nullable()->comment('Meta final anual');
                $t->decimal('band_min', 16, 4)->nullable()->comment('Limite inferior (manter_faixa)');
                $t->decimal('band_max', 16, 4)->nullable()->comment('Limite superior (manter_faixa)');
                $t->decimal('weight', 8, 2)->nullable();
                // auto | manual (como as metas mensais são distribuídas)
                $t->string('distribution_mode', 10)->default('manual');
                // manual | automatico (origem do realizado)
                $t->string('data_source_type', 10)->default('manual');
                $t->string('data_source_key', 100)->nullable()->comment('Chave do indicador para integração futura');
                // planejado | em_andamento | concluido | cancelado | descontinuado
                $t->string('status', 20)->default('planejado');
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->softDeletes();

                $t->index('okr_objective_id');
                $t->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_key_results');
    }
};
