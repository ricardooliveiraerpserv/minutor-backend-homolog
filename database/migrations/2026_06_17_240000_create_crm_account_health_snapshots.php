<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap Fase 1 — Saúde da Conta. Snapshots periódicos (histórico de evolução) +
 * base do painel executivo. Camada NOVA; não toca CRM/Contratos/Projetos/360.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_account_health_snapshots')) return;
        Schema::create('crm_account_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('score')->default(0);
            $table->string('status', 12);                 // saudavel | atencao | critico
            $table->json('motivos')->nullable();
            $table->decimal('margem', 16, 2)->nullable();  // referência financeira no snapshot
            $table->date('competencia')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['customer_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_account_health_snapshots');
    }
};
