<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evolui as Metas Comerciais (crm_sales_targets): tipo de meta (receita/margem/
 * quantidade/novos clientes/recorrente/projeto/sustentação) + observação, e um
 * histórico de alterações (quem, quando, valor anterior) para auditoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_sales_targets', function (Blueprint $t) {
            if (!Schema::hasColumn('crm_sales_targets', 'tipo')) $t->string('tipo', 30)->default('receita')->after('valor_meta');
            if (!Schema::hasColumn('crm_sales_targets', 'observacao')) $t->text('observacao')->nullable()->after('tipo');
        });

        if (!Schema::hasTable('crm_sales_target_history')) {
            Schema::create('crm_sales_target_history', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->unsignedBigInteger('target_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->char('periodo', 7)->index();
                $t->string('tipo', 30)->default('receita');
                $t->decimal('valor_anterior', 15, 2)->nullable();
                $t->decimal('valor_novo', 15, 2);
                $t->text('observacao')->nullable();
                $t->unsignedBigInteger('changed_by_id')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_sales_target_history');
        Schema::table('crm_sales_targets', function (Blueprint $t) {
            $t->dropColumn(['tipo', 'observacao']);
        });
    }
};
