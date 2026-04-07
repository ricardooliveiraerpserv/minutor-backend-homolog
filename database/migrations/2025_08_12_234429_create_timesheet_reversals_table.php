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
        Schema::create('timesheet_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timesheet_id')->constrained('timesheets')->onDelete('cascade');
            $table->foreignId('reversed_by')->constrained('users')->onDelete('cascade');
            $table->text('reversal_reason');
            $table->foreignId('original_approver_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('original_approval_date');
            $table->timestamps();
            
            // Índices para performance
            $table->index(['timesheet_id', 'created_at']);
            $table->index('reversed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timesheet_reversals');
    }
};
