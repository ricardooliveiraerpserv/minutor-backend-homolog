<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marca de leitura por (comunicado, usuário-cliente) — controla o popup de "comunicado novo". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('communication_id')->constrained('communications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();
            $table->timestamps();
            $table->unique(['communication_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_reads');
    }
};
