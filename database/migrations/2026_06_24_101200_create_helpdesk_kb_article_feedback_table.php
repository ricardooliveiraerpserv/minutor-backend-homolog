<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk — Feedback de artigo da Base de Conhecimento ("isso foi útil?").
 * Autor pode ser usuário interno OU contato do cliente (via Portal). Alimenta
 * helpful_count / not_helpful_count do artigo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helpdesk_kb_article_feedback')) {
            return;
        }
        Schema::create('helpdesk_kb_article_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kb_article_id')->constrained('helpdesk_kb_articles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_contact_id')->nullable()->constrained('customer_contacts')->nullOnDelete();
            $table->boolean('helpful');
            $table->text('comment')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['kb_article_id', 'helpful']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_kb_article_feedback');
    }
};
