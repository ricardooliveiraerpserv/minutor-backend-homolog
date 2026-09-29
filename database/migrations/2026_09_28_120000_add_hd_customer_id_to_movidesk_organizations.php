<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo DEDICADO do Help Desk: organização Movidesk → cliente Minutor, SEPARADO do
 * `customer_id` (que é compartilhado com Portal de Sustentação / roteamento de apontamento).
 *
 * Nasce NULL para todas as orgs: a integração de chamados (import) só age para as orgs que
 * o admin vincular aqui — "funciona somente para quem estiver vinculado".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movidesk_organizations', function (Blueprint $t) {
            if (!Schema::hasColumn('movidesk_organizations', 'hd_customer_id')) {
                $t->unsignedBigInteger('hd_customer_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('movidesk_organizations', function (Blueprint $t) {
            $t->dropColumn('hd_customer_id');
        });
    }
};
