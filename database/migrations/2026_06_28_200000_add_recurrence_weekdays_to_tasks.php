<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Recorrência semanal por dias da semana: [0..6] (0=domingo). Nulo = toda semana (1 dia). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('recurrence_weekdays')->nullable()->after('recurrence_interval');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('recurrence_weekdays'));
    }
};
