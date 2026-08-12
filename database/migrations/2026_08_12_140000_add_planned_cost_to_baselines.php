<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cronograma — EVM Fase 3: custo planejado (BAC em R$) congelado na baseline.
 * planned_cost por item = horas planejadas × custo/hora do consultor alocado na competência do congelamento.
 * Aditiva + idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stage_baseline_items') && ! Schema::hasColumn('stage_baseline_items', 'planned_cost')) {
            Schema::table('stage_baseline_items', function (Blueprint $table) {
                $table->decimal('planned_cost', 14, 2)->default(0)->after('planned_hours');
            });
        }
        if (Schema::hasTable('project_baselines') && ! Schema::hasColumn('project_baselines', 'planned_cost_total')) {
            Schema::table('project_baselines', function (Blueprint $table) {
                $table->decimal('planned_cost_total', 14, 2)->default(0)->after('planned_hours_total');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stage_baseline_items', 'planned_cost')) {
            Schema::table('stage_baseline_items', fn (Blueprint $t) => $t->dropColumn('planned_cost'));
        }
        if (Schema::hasColumn('project_baselines', 'planned_cost_total')) {
            Schema::table('project_baselines', fn (Blueprint $t) => $t->dropColumn('planned_cost_total'));
        }
    }
};
