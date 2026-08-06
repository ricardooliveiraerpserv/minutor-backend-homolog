<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Custo (fixo) por produto dentro da oportunidade — base da margem por linha. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_opportunity_products', function (Blueprint $t) {
            if (!Schema::hasColumn('crm_opportunity_products', 'custo')) {
                $t->decimal('custo', 14, 2)->default(0)->after('valor');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_opportunity_products', function (Blueprint $t) {
            if (Schema::hasColumn('crm_opportunity_products', 'custo')) $t->dropColumn('custo');
        });
    }
};
