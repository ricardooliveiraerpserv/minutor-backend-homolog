<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk — Base de Conhecimento: artigos. Imagens/anexos do corpo vão pela
 * Attachment Engine global (entity HELPDESK_KB_ARTICLE). Consumido pelo Portal do
 * Cliente exclusivamente via API Minutor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helpdesk_kb_articles')) {
            return;
        }
        Schema::create('helpdesk_kb_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kb_category_id')->nullable()->constrained('helpdesk_kb_categories')->nullOnDelete();
            $table->string('title', 200);
            $table->string('slug', 220)->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            $table->string('status', 12)->default('draft');        // draft | published | archived
            $table->string('visibility', 12)->default('customer'); // customer | internal
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('not_helpful_count')->default(0);
            $table->boolean('pinned')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'visibility']);
            $table->index('kb_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_kb_articles');
    }
};
