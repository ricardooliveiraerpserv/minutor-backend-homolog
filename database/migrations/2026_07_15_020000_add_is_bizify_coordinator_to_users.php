<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-empresa: flag "Coordenador Bizify?" no usuário. Quando ligada, o usuário
 * (coordenador, admin ou executivo) ganha uma coluna própria no Kanban de Contratos
 * SÓ quando a empresa ativa é BIZIFY (colunas por coordenador Bizify + SaaS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_bizify_coordinator')) {
                $table->boolean('is_bizify_coordinator')->default(false)->after('is_bizify');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_bizify_coordinator')) {
                $table->dropColumn('is_bizify_coordinator');
            }
        });
    }
};
