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
        Schema::create('consultant_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Nome do grupo de consultores');
            $table->text('description')->nullable()->comment('Descrição do grupo');
            $table->boolean('active')->default(true)->comment('Indica se o grupo está ativo');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null')->comment('Usuário que criou o grupo');
            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index('name');
            $table->index('active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultant_groups');
    }
};

