<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes iniciais de rentabilidade por cliente × ANO: custo inicial e receita inicial
 * lançados manualmente (ex.: saldos anteriores ao Minutor/Keruak). Somam no Custo Operação
 * e no Valor Recebido do ano selecionado na tela de Rentabilidade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_rentab_initials')) {
            return;
        }
        Schema::create('customer_rentab_initials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->decimal('custo_inicial', 14, 2)->default(0);
            $table->decimal('receita_inicial', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['customer_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_rentab_initials');
    }
};
