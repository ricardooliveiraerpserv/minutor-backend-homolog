<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Notificações (tela inicial). Notificações informativas, acionáveis e
 * obrigatórias com aceite (require_ack) + auditoria do aceite + versionamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_center', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('message');
            $table->string('type', 20)->default('info');        // info | action | require_ack
            $table->string('priority', 12)->default('medium');  // low | medium | high | critical
            $table->json('target_roles')->nullable();           // ['admin','consultor','coordenador']
            $table->json('target_users')->nullable();           // [userId,...] ou null
            $table->boolean('requires_ack')->default(false);
            $table->string('cta_label', 60)->nullable();
            $table->string('cta_url', 500)->nullable();
            $table->unsignedInteger('version')->default(1);     // muda → exige novo aceite
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['type', 'priority']);
        });

        Schema::create('notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications_center')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('ack_at')->nullable();
            $table->unsignedInteger('acked_version')->nullable(); // versão aceita (p/ versionamento)
            $table->string('ack_ip', 45)->nullable();
            $table->text('ack_user_agent')->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
        Schema::dropIfExists('notifications_center');
    }
};
