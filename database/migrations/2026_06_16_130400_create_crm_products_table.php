<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — catálogo de Produtos e Serviços. Mais rico que service_types/contract_types
 * (categoria, precificação, valor, descrição), com mapeamento opcional p/ contract_type
 * e tipo_faturamento usado na CONVERSÃO comercial → contrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_products')) {
            return;
        }
        Schema::create('crm_products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('categoria', 40);                    // Licenciamento/Implantação/Sustentação/Banco de Horas/Pacote de Horas/Projeto Fechado/Treinamento/Customização
            $table->string('tipo_precificacao', 20);            // hora | projeto | mensal | licenca
            $table->decimal('valor', 14, 2)->nullable();
            $table->text('descricao_tecnica')->nullable();
            $table->boolean('ativo')->default(true);
            // Mapeamento p/ conversão (reuso do fluxo de contrato). Opcionais.
            $table->foreignId('contract_type_id')->nullable()->constrained('contract_types')->nullOnDelete();
            $table->foreignId('service_type_id')->nullable()->constrained('service_types')->nullOnDelete();
            $table->string('tipo_faturamento', 30)->nullable(); // on_demand|banco_horas_mensal|banco_horas_fixo|por_servico|saas
            $table->string('categoria_contrato', 12)->nullable(); // projeto|sustentacao (categoria do contrato gerado)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_products');
    }
};
