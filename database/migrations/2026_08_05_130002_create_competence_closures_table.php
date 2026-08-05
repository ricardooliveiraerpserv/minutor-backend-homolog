<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encerramento MANUAL de competência (semana ou mês) ANTES do prazo automático.
 * É o inverso do reopen: marca o período como fechado já. project_id/user_id null = global.
 * Uma reabertura ATIVA (auto_close_at futuro) sobrepõe o closure enquanto durar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('competence_closures')) return;

        Schema::create('competence_closures', function (Blueprint $table) {
            $table->id();
            $table->string('period_kind', 10);   // week | month
            $table->string('period_key', 20);    // week_start Y-m-d | Y-m
            $table->foreignId('project_id')->nullable(); // null = global
            $table->foreignId('user_id')->nullable();    // null = todos
            $table->foreignId('closed_by')->nullable();
            $table->timestamp('closed_at');
            $table->timestamps();
            $table->index(['period_kind', 'period_key']);
        });

        // 1 closure por escopo/período (reabrir cria reopen; re-encerrar reusa a linha).
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS competence_closures_scope_uq ON competence_closures (period_kind, period_key, COALESCE(project_id, 0), COALESCE(user_id, 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('competence_closures');
    }
};
