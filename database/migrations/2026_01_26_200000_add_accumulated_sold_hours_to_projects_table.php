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
        // Verificar se a coluna já existe antes de adicionar
        if (!Schema::hasColumn('projects', 'accumulated_sold_hours')) {
            Schema::table('projects', function (Blueprint $table) {
                // Adicionar coluna para armazenar a somatória de horas vendidas mês a mês
                // Usado apenas para projetos do tipo "Banco de Horas Mensal"
                $table->integer('accumulated_sold_hours')->nullable()->after('sold_hours')
                    ->comment('Somatória de horas vendidas mês a mês desde a data de início (apenas para Banco de Horas Mensal)');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('accumulated_sold_hours');
        });
    }
};
