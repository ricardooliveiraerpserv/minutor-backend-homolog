<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contrato Aditivo: um "modo" do contrato que NÃO gera projeto — ele ALTERA um
     * projeto pai/independente existente (valor-hora, horas vendidas ou valor do
     * contrato), reusando a vigência (effective_from). A nova quantia vai nos campos
     * existentes (valor_hora / horas_contratadas / valor_projeto) conforme aditivo_field.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->boolean('is_aditivo')->default(false);
            // Projeto alvo (pai ou independente — parent_project_id IS NULL).
            $table->foreignId('aditivo_project_id')->nullable()->constrained('projects')->nullOnDelete();
            // O que o aditivo altera: 'valor_hora' | 'horas_contratadas' | 'valor_projeto'.
            $table->string('aditivo_field')->nullable();
            // Mês de vigência da alteração (valor_hora/horas); null p/ valor_projeto (Cloud).
            $table->date('aditivo_effective_from')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aditivo_project_id');
            $table->dropColumn(['is_aditivo', 'aditivo_field', 'aditivo_effective_from']);
        });
    }
};
