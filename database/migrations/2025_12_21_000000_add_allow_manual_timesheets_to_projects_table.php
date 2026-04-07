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
            $table->boolean('allow_manual_timesheets')
                ->default(true)
                ->after('timesheet_retroactive_limit_days')
                ->comment('Permite criação de apontamentos pelo frontend. Se false, apenas webhook pode criar apontamentos. Administradores são exceção.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('allow_manual_timesheets');
        });
    }
};

