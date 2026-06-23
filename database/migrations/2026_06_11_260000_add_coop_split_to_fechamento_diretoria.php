<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechamento_diretoria', function (Blueprint $table) {
            foreach (['valor_coop_erpserv', 'valor_coop_bizify', 'taxa_coop_erpserv', 'taxa_coop_bizify'] as $col) {
                if (!Schema::hasColumn('fechamento_diretoria', $col)) {
                    $table->decimal($col, 14, 2)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_diretoria', function (Blueprint $table) {
            foreach (['valor_coop_erpserv', 'valor_coop_bizify', 'taxa_coop_erpserv', 'taxa_coop_bizify'] as $col) {
                if (Schema::hasColumn('fechamento_diretoria', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
