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
        Schema::create('project_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('changed_by')->constrained('users')->onDelete('cascade');
            $table->string('field_name'); // Nome do campo alterado (ex: project_value, hourly_rate)
            $table->text('old_value')->nullable(); // Valor antigo (JSON para flexibilidade)
            $table->text('new_value')->nullable(); // Valor novo (JSON para flexibilidade)
            $table->text('reason')->nullable(); // Motivo da alteração
            $table->timestamps();

            // Índices para melhorar performance de consultas
            $table->index('project_id');
            $table->index('changed_by');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_change_logs');
    }
};
