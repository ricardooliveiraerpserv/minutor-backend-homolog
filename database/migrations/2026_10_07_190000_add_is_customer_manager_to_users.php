<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Gestor do cliente: usuário cliente que enxerga TODOS os projetos da sua empresa
 * (não precisa ser convidado por card). Os demais clientes só veem os projetos em
 * que foram adicionados como viewer (project_client_viewers).
 */
return new class extends Migration {
    public function up(): void
    {
        $fresh = false;
        Schema::table('users', function (Blueprint $table) use (&$fresh) {
            if (! Schema::hasColumn('users', 'is_customer_manager')) {
                $table->boolean('is_customer_manager')->default(false)->after('customer_id');
                $fresh = true;
            }
        });

        // Backfill (apenas na 1ª criação da coluna): clientes ATIVOS já existentes
        // viram gestor para NÃO perder a visão atual dos projetos. Novos clientes
        // nascem restritos (default false) — só veem os projetos em que forem convidados.
        if ($fresh) {
            DB::table('users')
                ->where('type', 'cliente')
                ->where('enabled', true)
                ->update(['is_customer_manager' => true]);
        }
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
