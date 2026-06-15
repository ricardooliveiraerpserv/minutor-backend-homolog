<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Coop (erpserv|bizify) por adiantamento da rotina no mês — mapa
        // { adiantamento_id: coop }. Cada adiantamento pode ir para uma coop diferente.
        Schema::table('fechamento_diretoria', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_diretoria', 'adiantamento_coops')) {
                $table->json('adiantamento_coops')->nullable()->after('adiantamento_coop');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_diretoria', function (Blueprint $table) {
            if (Schema::hasColumn('fechamento_diretoria', 'adiantamento_coops')) {
                $table->dropColumn('adiantamento_coops');
            }
        });
    }
};
