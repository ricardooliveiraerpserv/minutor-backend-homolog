<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timeline + auditoria do Follow Up (append-only, sem updated_at). Espelha
 * stage_activity_events: comentários e eventos de mudança no mesmo lugar (ADR 0005).
 * Anexo de comentário inline (igual ao chat do cronograma).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('follow_up_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follow_up_id')->constrained('follow_ups')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->jsonb('payload')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['follow_up_id', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_events');
    }
};
