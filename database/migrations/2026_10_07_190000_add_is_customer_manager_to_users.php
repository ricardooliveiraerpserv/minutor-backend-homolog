<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestor do cliente: usuário cliente que enxerga TODOS os projetos da sua empresa
 * (não precisa ser convidado por card). Os demais clientes só veem os projetos em
 * que foram adicionados como viewer (project_client_viewers).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_customer_manager')) {
                $table->boolean('is_customer_manager')->default(false)->after('customer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_customer_manager')) {
                $table->dropColumn('is_customer_manager');
            }
        });
    }
};
