<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->integer('timesheet_retroactive_limit_days')
                ->nullable()
                ->after('unlimited_expense')
                ->comment('Prazo limite (em dias) para lançamento retroativo de horas. NULL = usar configuração global');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('timesheet_retroactive_limit_days');
        });
    }
};

