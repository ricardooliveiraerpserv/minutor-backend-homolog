<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotência do "Finalizar Atendimento" (R1 camada 1). Cada operação tem uma chave
 * única gerada pelo FE; a MESMA chave nunca produz dois apontamentos — o backend devolve
 * o resultado da primeira execução (retry/timeout/refresh/dupla submissão/2 abas).
 * Só operações BEM-SUCEDIDAS são gravadas (falha não é cacheada → permite re-tentativa).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helpdesk_finalize_operations')) {
            return;
        }
        Schema::create('helpdesk_finalize_operations', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 80)->unique();
            $table->foreignId('helpdesk_ticket_id')->constrained('helpdesk_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('status_code');
            $table->json('response');
            $table->timestamps();
            $table->index('helpdesk_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_finalize_operations');
    }
};
