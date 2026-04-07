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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade')->comment('Solicitante');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('expense_category_id')->constrained('expense_categories')->onDelete('restrict');
            $table->date('expense_date')->comment('Data da despesa');
            $table->text('description')->comment('Descrição da despesa');
            $table->decimal('amount', 10, 2)->comment('Valor da despesa');
            $table->enum('expense_type', ['corporate_card', 'reimbursement'])->comment('Tipo: Cartão corporativo ou Reembolso');
            $table->enum('payment_method', ['corporate_card', 'cash', 'bank_transfer', 'pix', 'check', 'other'])->comment('Forma de pagamento');
            $table->string('receipt_path')->nullable()->comment('Caminho do comprovante anexo');
            $table->string('receipt_original_name')->nullable()->comment('Nome original do arquivo');
            $table->enum('status', ['pending', 'approved', 'rejected', 'adjustment_requested'])->default('pending');
            $table->text('rejection_reason')->nullable()->comment('Motivo da rejeição ou ajuste solicitado');
            $table->boolean('charge_client')->default(false)->comment('Se será cobrado do cliente');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null')->comment('Quem revisou');
            $table->timestamp('reviewed_at')->nullable()->comment('Quando foi revisado');
            $table->timestamps();

            // Índices para performance
            $table->index(['user_id', 'expense_date']);
            $table->index(['project_id', 'expense_date']);
            $table->index(['status']);
            $table->index(['expense_date']);
            $table->index(['expense_type']);
            $table->index(['reviewed_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
