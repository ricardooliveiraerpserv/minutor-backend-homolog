<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Já renomeada: nada a fazer
        if (Schema::hasTable('project_coordinators')) {
            return;
        }

        if (Schema::hasTable('project_approvers')) {
            Schema::rename('project_approvers', 'project_coordinators');
        } else {
            // Nenhuma das duas existe: criar do zero
            Schema::create('project_coordinators', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('project_coordinators') && !Schema::hasTable('project_approvers')) {
            Schema::rename('project_coordinators', 'project_approvers');
        }
    }
};
