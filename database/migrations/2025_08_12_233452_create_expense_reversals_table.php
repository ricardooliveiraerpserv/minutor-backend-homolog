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
        Schema::create('expense_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->onDelete('cascade');
            $table->foreignId('reversed_by')->constrained('users')->onDelete('cascade');
            $table->text('reversal_reason');
            $table->foreignId('original_approver_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('original_approval_date');
            $table->timestamps();
            
            // Índices para performance
            $table->index(['expense_id', 'created_at']);
            $table->index('reversed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_reversals');
    }
};
