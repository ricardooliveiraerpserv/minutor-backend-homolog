<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editor de Proposta — overrides de CONTEÚDO editável por proposta (texto dos slides) + logo do cliente.
 * Os números da margem continuam em crm_proposal_calc.inputs; aqui ficam só os textos/overrides de
 * apresentação (escopo funcional, despesas, parcelas, início, etc.) via OVERLAY-ON-OVERRIDE + logo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_proposals', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_proposals', 'conteudo')) {
                $table->json('conteudo')->nullable()->after('memoria_calculo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_proposals', function (Blueprint $table) {
            if (Schema::hasColumn('crm_proposals', 'conteudo')) {
                $table->dropColumn('conteudo');
            }
        });
    }
};
