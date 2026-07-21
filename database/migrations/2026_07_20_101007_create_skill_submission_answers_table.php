<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resposta por competência dentro de uma submissão. Nunca sobrescrita entre
 * avaliações (cada submissão tem o seu conjunto). level_weight é desnormalizado
 * p/ o snapshot histórico sobreviver a mudanças em skill_levels.
 *
 * "Nenhum conhecimento" = level de weight 0 (resposta explícita, não branco).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_submission_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('skill_submissions')->cascadeOnDelete();
            $table->foreignId('matrix_version_item_id')->nullable()->constrained('skill_matrix_version_items')->nullOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->foreignId('level_id')->nullable()->constrained('skill_levels');
            $table->unsignedTinyInteger('level_weight')->nullable();
            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->json('atuacao')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['submission_id', 'matrix_version_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_submission_answers');
    }
};
