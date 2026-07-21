<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destinatário convidado de uma pesquisa + tracking (estilo MS Forms).
 *
 * Cada convite tem um token individual (link próprio) e acompanha o funil:
 * pending → sent → opened → started → submitted. `submission_id` é uma ligação
 * leve (sem FK, evita circularidade com skill_submissions) preenchida no envio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_survey_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('skill_surveys')->cascadeOnDelete();
            $table->foreignId('respondent_id')->nullable()->constrained('skill_respondents')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email', 190)->nullable();
            $table->string('name', 160)->nullable();
            $table->string('token', 40)->unique();
            $table->string('status', 20)->default('pending')
                ->comment('pending | sent | opened | started | submitted');
            $table->unsignedBigInteger('submission_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_access_at')->nullable();
            $table->unsignedSmallInteger('reminder_count')->default(0);
            $table->timestamp('last_reminder_at')->nullable();
            $table->timestamps();

            $table->index(['survey_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_survey_invites');
    }
};
