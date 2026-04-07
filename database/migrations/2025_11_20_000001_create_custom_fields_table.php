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
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->enum('context', ['Project', 'Timesheet', 'Expense', 'Customer']);
            $table->string('label');
            $table->string('key'); // slug único por contexto
            $table->enum('type', ['text', 'number', 'boolean', 'date', 'select']);
            $table->boolean('required')->default(false);
            $table->json('options')->nullable(); // Para type=select
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            // Garantir que o key seja único por contexto
            $table->unique(['context', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};

