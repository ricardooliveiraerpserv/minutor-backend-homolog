<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Objetivos do OKR. Mesma tabela para corporativos (level=corporativo, sem área)
 * e de área (level=area, com okr_area_id). Objetivos de área podem apontar para
 * um objetivo corporativo via parent_objective_id (desdobramento).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('okr_objectives')) {
            Schema::create('okr_objectives', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $t->foreignId('okr_cycle_id')->constrained('okr_cycles')->cascadeOnDelete();
                // corporativo | area
                $t->string('level', 20)->default('area');
                $t->foreignId('okr_area_id')->nullable()->constrained('okr_areas')->nullOnDelete();
                // Desdobramento: objetivo de área contribui para um objetivo corporativo.
                $t->foreignId('parent_objective_id')->nullable()->constrained('okr_objectives')->nullOnDelete();
                $t->string('title', 255);
                $t->text('description')->nullable();
                $t->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
                $t->date('start_date')->nullable();
                $t->date('end_date')->nullable();
                // baixa | media | alta
                $t->string('priority', 20)->default('media');
                // planejado | em_andamento | concluido | cancelado
                $t->string('status', 20)->default('planejado');
                $t->decimal('weight', 8, 2)->nullable()->comment('Peso para consolidação ponderada');
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->softDeletes();

                $t->index('okr_cycle_id');
                $t->index('okr_area_id');
                $t->index('level');
                $t->index('parent_objective_id');
                $t->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_objectives');
    }
};
