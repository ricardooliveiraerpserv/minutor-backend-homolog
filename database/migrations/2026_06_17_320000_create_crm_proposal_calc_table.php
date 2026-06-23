<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 — Memória de Cálculo como ENTIDADE (não só PDF). Congelada por versão.
 * Replica as fórmulas do Excel (coordenação 20% / imposto 10% / custo fixo 40% / margem / prêmios).
 * modo_faturamento: por_hora (BH Fixo/Mensal/On Demand) | valor_fixo (Projeto Fechado).
 * Doc: docs/crm-propostas-analise.md
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('crm_proposal_calc')) {
            return;
        }
        Schema::create('crm_proposal_calc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->nullable()->constrained('crm_opportunities')->nullOnDelete();
            $table->foreignId('proposal_id')->nullable()->constrained('crm_proposals')->nullOnDelete();
            $table->unsignedInteger('versao')->default(1);
            $table->string('modo_faturamento', 16)->default('por_hora'); // por_hora | valor_fixo

            $table->json('inputs')->nullable();   // horas, valores/hora, parâmetros (%), prêmios, desconto
            $table->json('outputs')->nullable();  // detalhamento completo (linhas + intermediários)

            // Totalizadores (colunas p/ relatório/consulta sem abrir o JSON)
            $table->decimal('custo_total', 14, 2)->default(0);
            $table->decimal('faturamento', 14, 2)->default(0);
            $table->decimal('premios_total', 14, 2)->default(0);
            $table->decimal('desconto_valor', 14, 2)->default(0);
            $table->decimal('margem_pct', 8, 4)->default(0);
            $table->decimal('lucro_liquido', 14, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->index(['opportunity_id', 'versao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_proposal_calc');
    }
};
