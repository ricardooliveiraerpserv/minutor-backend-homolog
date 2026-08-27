<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diário da Atividade: notas (comentário + anexos) por entrega do cronograma.
 * Acesso interno (consultor/coordenador/admin) — cliente não vê (rota block.cliente).
 * Anexos ficam na infra FASE 11 (entity_type = DELIVERY_DIARY_ENTRY).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('delivery_diary_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('stage_deliveries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('body')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['delivery_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_diary_entries');
    }
};
