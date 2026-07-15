<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inclusões MANUAIS na rotina de reajuste — itens que NÃO têm contrato cadastrado
 * (ex.: licenças, Bizify, sustentação sem contrato). São só rastreio (saldo +
 * datas), não passam pelo fluxo de aplicar/notificar. Distinguidos na tela por
 * cor e pelo campo `empresa` (ERPSERV | BIZIFY).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('manual_reajustes')) {
            return;
        }

        Schema::create('manual_reajustes', function (Blueprint $table) {
            $table->id();
            $table->string('cliente_nome', 180);
            $table->string('descricao', 200)->nullable();      // "nome do contrato"
            $table->string('empresa', 12)->default('ERPSERV'); // ERPSERV | BIZIFY
            $table->decimal('valor_inicial', 14, 2)->nullable();
            $table->date('data_assinatura')->nullable();
            $table->date('data_ultimo_reajuste')->nullable();
            $table->date('data_vencimento')->nullable();        // próximo (último + 1 ano)
            $table->string('taxa_reajuste', 12)->nullable();    // IPCA | IGPM
            $table->decimal('pct_reajuste', 7, 3)->nullable();
            $table->timestamps();
            $table->index('empresa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_reajustes');
    }
};
