<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Classificação do respondente (editável): Pré-candidato / Candidato / ERPSERV /
 * Terceiro / PJ / Alocação TOTVS / Ex-funcionário / Black List. Black List são
 * candidatos que já deram problema (destacados na UP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_respondents', function (Blueprint $table) {
            $table->string('classification', 30)->nullable()->index()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('skill_respondents', function (Blueprint $table) {
            $table->dropColumn('classification');
        });
    }
};
