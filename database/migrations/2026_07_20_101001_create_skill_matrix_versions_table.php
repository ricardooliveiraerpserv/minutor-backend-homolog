<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banco de Competências — versionamento da MATRIZ ÚNICA.
 *
 * Existe uma única matriz de conhecimento; ela é versionada. Cada versão
 * congela o conjunto de competências vigentes no momento da publicação
 * (skill_matrix_version_items). As respostas ficam vinculadas à versão usada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_matrix_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();          // v1, v2, ...
            $table->string('label', 120)->nullable();             // "Matriz 2026"
            $table->string('status', 20)->default('draft')
                ->comment('draft | active | archived');
            $table->text('notes')->nullable();
            $table->unsignedInteger('skills_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_matrix_versions');
    }
};
