<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Fontes — Frente A. LEDGER de custo POR FONTE (enforcement agregado entre passos).
 * Espelha o CampaignBudgetLedger (reserva atômica com lockForUpdate): garante
 * actual_cost + reserved_cost + estimated_next <= authorized_limit ANTES de cada passo pago.
 * Vive na ORQUESTRAÇÃO — o motor congelado continua com seu teto por passo intocado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('source_doc_cost_ledger')) {
            return;
        }
        Schema::create('source_doc_cost_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_doc_id');
            $table->decimal('actual_cost_usd', 10, 4)->default(0);      // somatório dos passos liquidados
            $table->decimal('reserved_cost_usd', 10, 4)->default(0);    // passo em voo
            $table->decimal('authorized_limit_usd', 10, 4)->default(0); // teto operacional vigente (auto ou aprovado)
            $table->timestamps();

            $table->unique('source_doc_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_doc_cost_ledger');
    }
};
