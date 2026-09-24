<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whitelist: usuários autorizados a lançar despesa via CARTÃO DE CRÉDITO da empresa.
        if (!Schema::hasColumn('users', 'can_expense_credit_card')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('can_expense_credit_card')->default(false)->after('is_diretor');
            });
        }
        // Marca a despesa como paga via cartão de crédito → aprovada automaticamente e
        // fora da fila de pagamento (a empresa já pagou no cartão).
        if (!Schema::hasColumn('expenses', 'is_credit_card')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->boolean('is_credit_card')->default(false)->after('is_paid');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'can_expense_credit_card')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('can_expense_credit_card');
            });
        }
        if (Schema::hasColumn('expenses', 'is_credit_card')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropColumn('is_credit_card');
            });
        }
    }
};
