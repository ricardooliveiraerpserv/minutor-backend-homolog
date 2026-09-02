<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Itens de uma despesa. Uma despesa (cabeçalho: projeto, data, tipo, forma de
 * pagamento) pode ter N itens, cada um com sua própria categoria, descrição,
 * valor e comprovante (anexo via camada global attachments, entity_type
 * 'EXPENSE_ITEM'). O `expenses.amount` continua sendo o TOTAL (= SUM dos itens),
 * mantendo intactos os 13+ pontos de agregação de fechamento/rentabilidade.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('expense_items')) {
            return;
        }

        Schema::create('expense_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->onDelete('cascade');
            $table->foreignId('expense_category_id')->constrained('expense_categories')->onDelete('restrict');
            $table->text('description');
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->index('expense_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_items');
    }
};
