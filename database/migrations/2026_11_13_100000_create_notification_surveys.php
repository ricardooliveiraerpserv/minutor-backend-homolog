<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PESQUISA (survey) = notifications_center type='survey' + formulário com VÁRIAS perguntas.
 * Diferente de enquete (poll = 1 pergunta + opções). Perguntas de 3 tipos: texto livre,
 * pessoa (usuário) e múltipla escolha (single/multiple). Anonimato definido na criação:
 * se NÃO anônima, a resposta é identificada pelo usuário (nome vem do login).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_surveys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('notification_id')->constrained('notifications_center')->cascadeOnDelete();
            $t->boolean('anonymous')->default(false);  // true = não identifica quem respondeu
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        Schema::create('notification_survey_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_id')->constrained('notification_surveys')->cascadeOnDelete();
            $t->string('label', 500);
            $t->string('type', 16)->default('text'); // text | person | choice
            $t->boolean('required')->default(true);
            $t->boolean('multiple')->default(false);  // choice: permite marcar várias
            $t->unsignedInteger('order')->default(0);
            $t->timestamps();
        });

        Schema::create('notification_survey_question_options', function (Blueprint $t) {
            $t->id();
            $t->foreignId('question_id')->constrained('notification_survey_questions')->cascadeOnDelete();
            $t->string('label', 200);
            $t->unsignedInteger('order')->default(0);
            $t->timestamps();
        });

        Schema::create('notification_survey_responses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_id')->constrained('notification_surveys')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // null = anônima
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
            $t->index(['survey_id', 'user_id']);
        });

        Schema::create('notification_survey_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('response_id')->constrained('notification_survey_responses')->cascadeOnDelete();
            $t->foreignId('question_id')->constrained('notification_survey_questions')->cascadeOnDelete();
            $t->text('answer_text')->nullable();                                              // texto livre
            $t->foreignId('answer_user_id')->nullable()->constrained('users')->nullOnDelete(); // pessoa
            $t->foreignId('option_id')->nullable()->constrained('notification_survey_question_options')->nullOnDelete(); // escolha
            $t->timestamps();
            $t->index(['question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_survey_answers');
        Schema::dropIfExists('notification_survey_responses');
        Schema::dropIfExists('notification_survey_question_options');
        Schema::dropIfExists('notification_survey_questions');
        Schema::dropIfExists('notification_surveys');
    }
};
