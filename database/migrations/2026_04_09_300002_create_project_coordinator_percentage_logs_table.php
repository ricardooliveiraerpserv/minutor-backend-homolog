<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_coordinator_percentage_logs')) {
            return;
        }

        Schema::create('project_coordinator_percentage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('changed_by')->constrained('users');
            $table->decimal('previous_percentage', 5, 2);
            $table->decimal('new_percentage', 5, 2);
            $table->decimal('previous_balance', 10, 2)->comment('Saldo do projeto antes do recálculo');
            $table->decimal('new_balance', 10, 2)->comment('Saldo do projeto após o recálculo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_coordinator_percentage_logs');
    }
};
