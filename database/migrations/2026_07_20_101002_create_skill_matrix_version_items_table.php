<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot congelado das competências de uma versão da matriz.
 *
 * name/category/section são desnormalizados de propósito: mesmo que a skill
 * seja renomeada/removida no futuro, a versão histórica permanece fiel ao que
 * foi apresentado ao respondente. `section` agrupa categorias em etapas do
 * wizard (ex.: Protheus Administrativo vs Protheus Logística).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_matrix_version_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matrix_version_id')->constrained('skill_matrix_versions')->cascadeOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->string('category', 80);
            $table->string('name', 120);
            $table->string('section', 80)->nullable()->comment('agrupamento de etapa do wizard');
            $table->string('skill_type', 20)->nullable()->comment('module | technology | process');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['matrix_version_id', 'skill_id']);
            $table->index(['matrix_version_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_matrix_version_items');
    }
};
