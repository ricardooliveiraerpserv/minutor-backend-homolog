<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Vigência da rotina: só gera tarefas entre start_date e end_date (ambos opcionais). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_groups', function (Blueprint $t) {
            if (!Schema::hasColumn('task_groups', 'start_date')) $t->date('start_date')->nullable()->after('active');
            if (!Schema::hasColumn('task_groups', 'end_date')) $t->date('end_date')->nullable()->after('start_date');
        });
    }

    public function down(): void
    {
        Schema::table('task_groups', function (Blueprint $t) {
            $t->dropColumn(['start_date', 'end_date']);
        });
    }
};
