<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Itens de contrato (SaaS/Cloud): cobranças pontuais além da mensalidade — Setup / Desenvolvimento /
 * Setup+Desenvolvimento. Cada item gera um CARD DE PROJETO próprio (tipo Fechado), mesmo contrato,
 * código = base do card mensal + sufixo de letra (ex.: ATR002-25-08-A). Não é filho.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contract_items')) {
            return;
        }
        Schema::create('contract_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('tipo', 20); // setup | desenvolvimento | setup_dev
            $table->text('descricao')->nullable();
            // Campos de valor (espelham um projeto Fechado)
            $table->decimal('valor_projeto', 14, 2)->nullable();
            $table->decimal('valor_hora', 14, 2)->nullable();
            $table->integer('horas_contratadas')->nullable();
            $table->decimal('hora_adicional', 14, 2)->nullable();
            $table->string('tipo_faturamento')->nullable();
            $table->text('condicao_pagamento')->nullable();
            $table->integer('pct_horas_coordenador')->nullable();
            $table->decimal('horas_coordenacao', 10, 2)->nullable();
            $table->decimal('horas_consultor', 10, 2)->nullable();
            // Geração do card
            $table->string('letter', 2)->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_items');
    }
};
