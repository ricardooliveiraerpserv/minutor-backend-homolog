<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria da Plataforma de Documentos (padrão ContractEvent: sequence atômico por documento).
 * Eventos: criado|regenerado|enviado|baixado|cancelado|reativado|assinado.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('document_events')) {
            return;
        }
        Schema::create('document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('sequence_number')->default(1);
            $table->string('event_type', 24);
            $table->json('meta')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['document_id', 'sequence_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_events');
    }
};
