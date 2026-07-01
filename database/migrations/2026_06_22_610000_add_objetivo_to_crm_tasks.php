<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CrmTask — "Objetivo da ação" (por que essa tarefa existe): facilita gestão e transição de carteira. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_tasks', 'objetivo')) $table->string('objetivo', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('crm_tasks', 'objetivo')) $table->dropColumn('objetivo');
        });
    }
};
