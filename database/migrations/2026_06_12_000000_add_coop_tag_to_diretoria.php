<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada lançamento pode ser marcado para uma cooperativa (erpserv | bizify),
        // somando automaticamente nos campos de divisão por coop.
        Schema::table('fechamento_diretoria_itens', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_diretoria_itens', 'coop')) {
                $table->string('coop', 10)->nullable()->after('valor'); // erpserv | bizify
            }
        });

        // A coop que recebe o desconto de adiantamento (parcelas da rotina) no mês.
        Schema::table('fechamento_diretoria', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_diretoria', 'adiantamento_coop')) {
                $table->string('adiantamento_coop', 10)->nullable()->after('taxa_coop_bizify'); // erpserv | bizify
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_diretoria_itens', function (Blueprint $table) {
            if (Schema::hasColumn('fechamento_diretoria_itens', 'coop')) {
                $table->dropColumn('coop');
            }
        });
        Schema::table('fechamento_diretoria', function (Blueprint $table) {
            if (Schema::hasColumn('fechamento_diretoria', 'adiantamento_coop')) {
                $table->dropColumn('adiantamento_coop');
            }
        });
    }
};
