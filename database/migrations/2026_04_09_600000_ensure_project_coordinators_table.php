<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_coordinators')) {
            return; // Já existe — nada a fazer
        }

        if (Schema::hasTable('project_approvers')) {
            Schema::rename('project_approvers', 'project_coordinators');
            return;
        }

        // Nenhuma das duas existe: criar do zero
        Schema::create('project_coordinators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['project_id', 'user_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_coordinators');
    }
};
