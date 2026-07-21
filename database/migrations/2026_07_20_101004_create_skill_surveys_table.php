<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pesquisa de Competências (campanha). O tipo escolhido pelo admin define
 * quais dados cadastrais aparecem antes da matriz e para onde a resposta vai —
 * a MATRIZ é a mesma para todos (matrix_version_id).
 *
 * public_token gera o link público:  /skills/{public_token}
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_surveys', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->comment('internal | partner | candidate');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->foreignId('matrix_version_id')->constrained('skill_matrix_versions');
            $table->string('public_token', 32)->unique();
            $table->string('status', 20)->default('draft')->comment('draft | open | closed');
            $table->date('deadline')->nullable();
            $table->boolean('allow_public')->default(false)
                ->comment('true p/ partner/candidate — portal público sem login individual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_surveys');
    }
};
