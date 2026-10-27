<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liberações de DESPESA por competência — "bloco de despesas" do Fechamento.
 * Despesa é bloqueada após o 2º dia útil do mês seguinte (regra em ClosingService);
 * uma liberação reabre a inclusão/edição para o escopo escolhido (global/cliente/
 * projeto/usuário — campos nulos = "qualquer"), fechando sozinha às 23:59 do dia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('expense_open_periods')) {
            return;
        }

        Schema::create('expense_open_periods', function (Blueprint $table) {
            $table->id();
            $table->string('year_month', 7);                          // competência AAAA-MM
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('auto_close_at')->nullable();           // = 23:59:59 do dia da abertura
            $table->timestamp('closed_at')->nullable();               // fechamento manual antecipado
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['year_month', 'closed_at']);
            $table->index('auto_close_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_open_periods');
    }
};
