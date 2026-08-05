<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reabertura SEMANAL (espelha project_open_periods, mas por semana e com auto-fechamento).
 * project_id NULL = reabertura GLOBAL (vale p/ todos os projetos naquela semana).
 * A semana é reaberta enquanto closed_at IS NULL E now() <= auto_close_at (23:59 do dia da reabertura).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('week_open_periods')) return;

        Schema::create('week_open_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete(); // null = global
            $table->date('week_start'); // segunda-feira da semana
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('auto_close_at')->nullable(); // 23:59 SP do dia da reabertura
            $table->timestamp('closed_at')->nullable();      // fechamento manual antecipado
            $table->timestamps();
            $table->index(['week_start', 'closed_at']);
        });

        // Unicidade: 1 linha por (projeto, semana) e 1 linha GLOBAL por semana (project_id null).
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS week_open_periods_project_week_uq ON week_open_periods (project_id, week_start) WHERE project_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS week_open_periods_global_week_uq ON week_open_periods (week_start) WHERE project_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('week_open_periods');
    }
};
