<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cor de identidade por empresa (multi-empresa) — pra deixar EVIDENTE qual empresa
 * está ativa (faixa/selo colorido). Seed de cores distintas p/ ERPSERV e BIZIFY.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'color')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->string('color', 9)->nullable()->after('slug'); // #RRGGBB
            });
            DB::table('companies')->where('slug', 'erpserv')->whereNull('color')->update(['color' => '#06b6d4']); // ciano
            DB::table('companies')->where('slug', 'bizify')->whereNull('color')->update(['color' => '#a855f7']);  // roxo
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'color')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->dropColumn('color');
            });
        }
    }
};
