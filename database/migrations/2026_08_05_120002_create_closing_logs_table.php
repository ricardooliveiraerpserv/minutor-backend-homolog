<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Log de encerramentos p/ acompanhamento: fechamento semanal por prazo, reaberturas
 * (semana/mês) e seus auto-fechamentos. unique(event,period_kind,period_key,project_id)
 * torna o log automático (scheduler) idempotente. Também guarda o 'activation' (marco
 * "daqui pra frente" da regra semanal).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('closing_logs')) return;

        Schema::create('closing_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);        // week_deadline_close | week_reopen | week_reopen_autoclose | month_reopen | month_reopen_autoclose | activation
            $table->string('period_kind', 10);  // week | month
            $table->string('period_key', 20);   // week: date da 2ª (Y-m-d) | month: Y-m
            $table->foreignId('project_id')->nullable(); // null = global
            $table->foreignId('user_id')->nullable();
            $table->timestamp('occurred_at');
            $table->string('note', 300)->nullable();
            $table->timestamps();
            $table->index('occurred_at');
            $table->index(['event', 'period_kind', 'period_key']); // idempotência do scheduler (checada em código)
        });

        // Marco de ativação da regra semanal (daqui pra frente).
        DB::table('closing_logs')->insert([
            'event'       => 'activation',
            'period_kind' => 'week',
            'period_key'  => 'activation',
            'project_id'  => null,
            'user_id'     => null,
            'occurred_at' => now(),
            'note'        => 'Ativação da regra de fechamento semanal',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('closing_logs');
    }
};
