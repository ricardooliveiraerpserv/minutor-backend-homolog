<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — Oportunidades (deals) + itens (produtos). Empresa = customers (não duplica).
 * contract_id é setado na CONVERSÃO comercial (fase 1D) — guarda idempotência 1 opp → 1 contrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_opportunities')) {
            Schema::create('crm_opportunities', function (Blueprint $table) {
                $table->id();
                $table->string('title', 180);
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->foreignId('pipeline_id')->constrained('crm_pipelines');
                $table->foreignId('stage_id')->constrained('crm_pipeline_stages');
                $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('tipo', 40)->nullable();          // tipo de oportunidade
                $table->string('origem', 60)->nullable();        // inbound/indicação/evento/etc.
                $table->decimal('valor', 14, 2)->default(0);
                $table->string('status', 12)->default('aberto'); // aberto | ganho | perdido
                $table->date('data_abertura')->nullable();
                $table->date('previsao_fechamento')->nullable();
                $table->timestamp('fechamento_at')->nullable();
                $table->string('motivo', 160)->nullable();       // motivo ganho/perda
                $table->integer('sla_dias')->nullable();
                $table->foreignId('campaign_id')->nullable()->constrained('crm_campaigns')->nullOnDelete();
                $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
                $table->timestamp('ultima_interacao_at')->nullable();
                $table->timestamp('proxima_acao_at')->nullable();
                $table->text('notas')->nullable();
                $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['pipeline_id', 'stage_id', 'status']);
                $table->index('customer_id');
                $table->index('responsavel_id');
            });
        }
        if (!Schema::hasTable('crm_opportunity_products')) {
            Schema::create('crm_opportunity_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('opportunity_id')->constrained('crm_opportunities')->cascadeOnDelete();
                $table->foreignId('crm_product_id')->constrained('crm_products');
                $table->decimal('quantidade', 12, 2)->default(1);
                $table->decimal('valor', 14, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunity_products');
        Schema::dropIfExists('crm_opportunities');
    }
};
