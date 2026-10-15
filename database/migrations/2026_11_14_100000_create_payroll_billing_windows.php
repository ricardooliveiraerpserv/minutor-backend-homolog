<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Janela(s) de pagamento por colaborador (usuário ou parceiro): define em quais
 * competências ele é calculado/pago nos fechamentos (diretoria, folha, consultor, parceiro).
 *
 * Modelo de RANGES (permite ciclos inativar→reativar com gap):
 * - start_month (YYYY-MM, null = aberto no início)  end_month (YYYY-MM, null = ativo/sem fim).
 * - Pagável na competência M se ALGUMA janela cobre M: (start<=M || start null) AND (end>=M || end null).
 * - SEM janela cadastrada = pagável sempre (comportamento legado preservado).
 *
 * Ao INATIVAR: fecha a janela aberta no mês escolhido (end_month=X, inclusivo) ou cria {null, X}.
 * Ao REATIVAR (se calcular folha): abre nova janela {start_month=Y (pode ser retroativo), end null}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_billing_windows')) {
            return;
        }
        Schema::create('payroll_billing_windows', function (Blueprint $table) {
            $table->id();
            $table->string('holder_type', 10);      // 'user' | 'partner'
            $table->unsignedBigInteger('holder_id');
            $table->char('start_month', 7)->nullable(); // 'YYYY-MM' (null = sem início)
            $table->char('end_month', 7)->nullable();   // 'YYYY-MM' (null = ativo/sem fim)
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index(['holder_type', 'holder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_billing_windows');
    }
};
