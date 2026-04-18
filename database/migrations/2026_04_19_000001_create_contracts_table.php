<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->enum('status', ['rascunho', 'aprovado', 'inicio_autorizado', 'ativo'])->default('rascunho');
            $table->enum('categoria', ['projeto', 'sustentacao']);
            $table->enum('tipo_contrato', ['aberto', 'fechado']);
            $table->enum('tipo_faturamento', ['on_demand', 'banco_horas_mensal', 'banco_horas_fixo', 'por_servico', 'saas']);
            $table->boolean('cobra_despesa_cliente')->default(false);
            $table->json('permissoes_despesa')->nullable();
            $table->foreignId('architect_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('tipo_alocacao', ['remoto', 'presencial', 'ambos'])->nullable();
            $table->integer('horas_contratadas')->default(0);
            $table->date('expectativa_inicio')->nullable();
            $table->text('condicao_pagamento')->nullable();
            $table->boolean('descontar_banco_horas')->default(false);
            $table->boolean('cobrar_a_parte')->default(false);
            $table->foreignId('executivo_conta_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('vendedor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('observacoes')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
