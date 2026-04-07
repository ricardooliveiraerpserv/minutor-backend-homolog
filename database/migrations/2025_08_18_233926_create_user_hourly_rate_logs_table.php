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
        Schema::create('user_hourly_rate_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade')
                ->comment('Usuário que teve o valor alterado');
            $table->foreignId('changed_by')->constrained('users')->onDelete('cascade')
                ->comment('Usuário que fez a alteração');
            $table->decimal('old_hourly_rate', 10, 2)->nullable()
                ->comment('Valor anterior (null se não tinha valor)');
            $table->decimal('new_hourly_rate', 10, 2)->nullable()
                ->comment('Novo valor (null se foi removido)');
            $table->enum('old_rate_type', ['hourly', 'monthly'])->nullable()
                ->comment('Tipo anterior do valor');
            $table->enum('new_rate_type', ['hourly', 'monthly'])->nullable()
                ->comment('Novo tipo do valor');
            $table->text('reason')->nullable()
                ->comment('Motivo da alteração (opcional)');
            $table->timestamps();

            // Índices para performance
            $table->index(['user_id', 'created_at']);
            $table->index('changed_by');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_hourly_rate_logs');
    }
};
