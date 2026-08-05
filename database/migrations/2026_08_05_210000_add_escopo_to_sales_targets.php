<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Prepara metas coletivas: escopo (individual/equipe/unidade/empresa) na meta comercial. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_sales_targets', function (Blueprint $t) {
            if (!Schema::hasColumn('crm_sales_targets', 'escopo')) $t->string('escopo', 20)->default('individual')->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('crm_sales_targets', function (Blueprint $t) {
            $t->dropColumn('escopo');
        });
    }
};
