<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_consultant_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consultant_group_id')->constrained('consultant_groups')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'consultant_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_consultant_groups');
    }
};
