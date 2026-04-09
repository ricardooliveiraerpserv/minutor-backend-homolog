<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_sold_hours_history')) {
            return;
        }

        Schema::create('project_sold_hours_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->integer('sold_hours');
            // Primeiro dia do mês a partir do qual essa quantidade de horas vigora
            $table->date('effective_from');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_sold_hours_history');
    }
};
