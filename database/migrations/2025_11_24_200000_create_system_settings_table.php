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
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('Chave única da configuração');
            $table->text('value')->nullable()->comment('Valor da configuração');
            $table->string('type')->default('string')->comment('Tipo do valor: string, integer, boolean, json');
            $table->string('group')->default('general')->comment('Grupo da configuração');
            $table->text('description')->nullable()->comment('Descrição da configuração');
            $table->timestamps();

            // Índices
            $table->index('key');
            $table->index('group');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};

