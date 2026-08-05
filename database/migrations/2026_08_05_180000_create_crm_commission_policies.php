<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Políticas de Comissão: regras que determinam o % por condições (cargo, pipeline,
 * faixa de valor, faixa de margem), avaliadas por prioridade. A primeira regra que
 * casa define o percentual; sem regra → cai no % por vendedor / padrão da empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_commission_policies')) return;
        Schema::create('crm_commission_policies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name');
            $t->boolean('active')->default(true);
            $t->integer('priority')->default(100); // menor = avaliado antes
            // Condições (null = qualquer)
            $t->string('cargo', 40)->nullable();
            $t->unsignedBigInteger('pipeline_id')->nullable();
            $t->decimal('min_valor', 15, 2)->nullable();
            $t->decimal('max_valor', 15, 2)->nullable();
            $t->decimal('min_margem', 5, 2)->nullable(); // % de margem
            $t->decimal('max_margem', 5, 2)->nullable();
            // Resultado
            $t->decimal('percentual', 5, 2)->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_commission_policies');
    }
};
