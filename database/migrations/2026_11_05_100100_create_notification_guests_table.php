<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Acompanhantes/familiares por respondente numa notificação de "Confirmar presença".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications_center')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // quem respondeu/leva
            $table->string('nome');
            $table->string('parentesco')->nullable();
            $table->timestamps();
            $table->index(['notification_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_guests');
    }
};
