<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escopo por USUÁRIO no multiplicador de horas (pedido do Ricardo).
 * `users` = array de user_id (apontadores) a quem a faixa se aplica.
 * NULL/vazio = TODOS os apontadores (comportamento atual preservado — as regras já
 * existentes continuam multiplicando todo mundo). Lista preenchida = SÓ esses usuários
 * têm as horas multiplicadas; os demais ficam no REAL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_hour_multipliers', function (Blueprint $table) {
            if (!Schema::hasColumn('contract_hour_multipliers', 'users')) {
                $table->jsonb('users')->nullable()->after('reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contract_hour_multipliers', function (Blueprint $table) {
            if (Schema::hasColumn('contract_hour_multipliers', 'users')) {
                $table->dropColumn('users');
            }
        });
    }
};
