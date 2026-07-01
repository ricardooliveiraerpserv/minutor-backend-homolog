<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Critério de conclusão da assinatura (P-E.2.2): todos | um_por_parte. Padrão: todos. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_proposals', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_proposals', 'assinatura_modo')) {
                $table->string('assinatura_modo', 24)->default('todos'); // todos | um_por_parte
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_proposals', function (Blueprint $table) {
            if (Schema::hasColumn('crm_proposals', 'assinatura_modo')) $table->dropColumn('assinatura_modo');
        });
    }
};
