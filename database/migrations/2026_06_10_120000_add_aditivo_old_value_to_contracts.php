<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Valor que o campo alterado tinha no projeto ANTES do aditivo — pra exibir
     * "antes → depois" no card do kanban. (O valor novo já fica em valor_hora /
     * horas_contratadas / valor_projeto conforme aditivo_field.)
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('aditivo_old_value', 15, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('aditivo_old_value');
        });
    }
};
