<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('consultant_group_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultant_group_id')->constrained('consultant_groups')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            // Garantir que um usuário não pode estar no mesmo grupo duas vezes
            $table->unique(['consultant_group_id', 'user_id']);
            
            // Índices para melhorar performance de queries
            $table->index('consultant_group_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultant_group_user');
    }
};

