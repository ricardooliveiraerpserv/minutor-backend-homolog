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
            // Remover o campo expense_policy
            $table->dropColumn('expense_policy');
            
            // Adicionar os novos campos
            $table->decimal('max_expense_per_consultant', 12, 2)->nullable()->comment('Valor máximo de despesa permitido por consultor');
            $table->enum('expense_responsible_party', ['consultancy', 'client'])->nullable()->comment('Quem será responsável pelo pagamento das despesas');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Reverter as mudanças
            $table->dropColumn(['max_expense_per_consultant', 'expense_responsible_party']);
            $table->text('expense_policy')->nullable();
        });
    }
};
