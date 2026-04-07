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
            $table->integer('consultant_hours')->nullable()->after('exceeded_hour_contribution')->comment('Quantidade de horas do consultor');
            $table->integer('coordinator_hours')->nullable()->after('consultant_hours')->comment('Quantidade de horas do coordenador');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['consultant_hours', 'coordinator_hours']);
        });
    }
};
