<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telas financeiras do CRM (Política Comercial): Metas, Comissões, Rentabilidade.
 * - crm_goals: meta mensal (R$) por responsável/competência. Realizado é derivado
 *   das oportunidades ganhas (status=ganho) — não se armazena aqui.
 * - crm_commission_rates: percentual de comissão por responsável (user_id nulo = padrão da empresa).
 * Rentabilidade usa o custo guardado em crm_opportunities.detalhes->custo (sem coluna nova).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_goals')) {
            Schema::create('crm_goals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->char('competencia', 7); // 'YYYY-MM'
                $t->decimal('valor_meta', 15, 2)->default(0);
                $t->timestamps();
                $t->unique(['company_id', 'user_id', 'competencia'], 'crm_goals_uq');
            });
        }

        if (!Schema::hasTable('crm_commission_rates')) {
            Schema::create('crm_commission_rates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable()->index(); // null = padrão da empresa
                $t->decimal('percentual', 5, 2)->default(0);
                $t->timestamps();
                $t->unique(['company_id', 'user_id'], 'crm_commrate_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_goals');
        Schema::dropIfExists('crm_commission_rates');
    }
};
