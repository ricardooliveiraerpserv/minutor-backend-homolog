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
        Schema::create('user_dashboard_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade')
                  ->comment('ID do usuário');
            $table->string('dashboard_type', 100)
                  ->comment('Tipo de dashboard (ex: bank_hours_fixed)');
            $table->timestamps();

            // Índices
            $table->index('user_id');
            $table->index('dashboard_type');
            
            // Garantir que um usuário não tenha o mesmo tipo de dashboard duplicado
            $table->unique(['user_id', 'dashboard_type'], 'user_dashboard_type_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_dashboard_types');
    }
};
