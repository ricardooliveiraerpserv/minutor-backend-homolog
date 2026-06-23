<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — Filtros salvos (conceito RD Station "Filtros salvos").
 * Guarda combinações de filtro por usuário e por tela (scope: opportunities, tasks…).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_saved_filters')) {
            return;
        }
        Schema::create('crm_saved_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope', 40);          // ex.: opportunities
            $table->string('name', 120);
            $table->json('payload');               // pares chave→valor dos filtros
            $table->timestamps();
            $table->index(['user_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_saved_filters');
    }
};
