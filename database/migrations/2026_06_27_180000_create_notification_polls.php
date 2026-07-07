<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enquetes (polls) integradas à Central de Notificações. Enquete NÃO é módulo separado:
 * é uma notificação com type='poll' + 1 registro em notification_polls (pergunta/opções/votos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications_center')->cascadeOnDelete();
            $table->string('question', 300);
            $table->boolean('multiple_choice')->default(false);  // 1 opção (default) ou várias
            $table->boolean('allow_change_vote')->default(true); // permite trocar/refazer o voto
            $table->timestamp('expires_at')->nullable();         // bloqueia votação depois disso
            $table->timestamps();
        });

        Schema::create('notification_poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('notification_polls')->cascadeOnDelete();
            $table->string('label', 200);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('notification_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('notification_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('notification_poll_options')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            // Não permitir voto duplicado na MESMA opção pelo mesmo usuário (auditoria 1:1).
            $table->unique(['option_id', 'user_id']);
            $table->index(['poll_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_poll_votes');
        Schema::dropIfExists('notification_poll_options');
        Schema::dropIfExists('notification_polls');
    }
};
