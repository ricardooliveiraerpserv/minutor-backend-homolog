<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lembretes pessoais do usuário (Central de Notificações). Simples: texto + data/hora opcionais. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('text', 500);
            $table->date('due_date')->nullable();
            $table->time('due_time')->nullable();
            $table->boolean('completed')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'completed']);
            $table->index(['user_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
