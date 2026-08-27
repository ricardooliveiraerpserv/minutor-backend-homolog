<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Separa CAMPANHAS de PESQUISAS/FORMULÁRIOS na tela de Competências (pedido do Ricardo).
 *
 * Campanha (auto-atualização interna) e Pesquisa/Formulário (Parceiros, Candidatos,
 * Base Histórica) reusam a MESMA tabela skill_surveys — todas podem ser type=internal,
 * então não havia como separar em abas. `is_campaign` marca as campanhas:
 *  - a auto-avaliação perene (public_token='AUTOAVAL'),
 *  - as lançadas via SkillSurveyController::launchCampaign (título "Atualização de Competências — MM/AAAA").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_surveys', function (Blueprint $table) {
            $table->boolean('is_campaign')->default(false)->after('allow_public');
        });

        // Backfill das campanhas já existentes: a AUTOAVAL perene + as de atualização lançadas.
        DB::table('skill_surveys')
            ->where('public_token', 'AUTOAVAL')
            ->orWhere('title', 'ILIKE', 'Atualização de Competências%')
            ->update(['is_campaign' => true]);
    }

    public function down(): void
    {
        Schema::table('skill_surveys', function (Blueprint $table) {
            $table->dropColumn('is_campaign');
        });
    }
};
