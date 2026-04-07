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
        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained('custom_fields')->onDelete('cascade');
            $table->unsignedBigInteger('entity_id'); // ID da entidade (Project/Timesheet/Expense/Customer)
            $table->text('value')->nullable(); // Armazena o valor como texto/json
            $table->timestamps();

            // Index para buscar valores por entidade
            $table->index(['custom_field_id', 'entity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
    }
};

