<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CRM — Propostas de uma oportunidade (número, versão, validade, valor, descontos, memória). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_proposals')) {
            return;
        }
        Schema::create('crm_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('crm_opportunities')->cascadeOnDelete();
            $table->integer('numero');
            $table->integer('versao')->default(1);
            $table->date('data_emissao')->nullable();
            $table->date('data_validade')->nullable();
            $table->decimal('valor', 14, 2)->default(0);
            $table->decimal('descontos', 14, 2)->default(0);
            $table->foreignId('vendedor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('memoria_calculo')->nullable();
            $table->string('status', 16)->default('rascunho'); // rascunho|enviada|aceita|recusada|expirada
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('opportunity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_proposals');
    }
};
