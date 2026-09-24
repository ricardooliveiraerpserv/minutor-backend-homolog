<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca "tem contrato?" no cadastro do cliente. Nasce DESLIGADA (false) para todos —
 * o admin liga quando houver contrato. Cliente sem contrato dispara o aviso de que os
 * fontes são apenas exemplo (Solicitação de Código-Fonte).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customers', 'has_contract')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->boolean('has_contract')->default(false)->after('active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'has_contract')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('has_contract');
            });
        }
    }
};
