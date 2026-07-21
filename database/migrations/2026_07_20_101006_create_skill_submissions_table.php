<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Submissão = envio da avaliação. IMUTÁVEL após submit (status=submitted):
 * nunca sobrescrevemos respostas anteriores — cada avaliação é um snapshot
 * ligado à versão da matriz. Enquanto in_progress, `progress` guarda o estado
 * do autosave (etapa atual + respostas parciais) p/ retomar de onde parou.
 *
 * `cadastral` congela os dados pessoais/cadastrais preenchidos no formulário.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('skill_surveys')->cascadeOnDelete();
            $table->foreignId('respondent_id')->constrained('skill_respondents')->cascadeOnDelete();
            $table->foreignId('matrix_version_id')->constrained('skill_matrix_versions');
            $table->foreignId('invite_id')->nullable()->constrained('skill_survey_invites')->nullOnDelete();
            $table->string('status', 20)->default('in_progress')->comment('in_progress | submitted');
            $table->json('cadastral')->nullable();
            $table->json('progress')->nullable()->comment('autosave: current_step + parciais');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['survey_id', 'respondent_id']);
            $table->index(['survey_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_submissions');
    }
};
