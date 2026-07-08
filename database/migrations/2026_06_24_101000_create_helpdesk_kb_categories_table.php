<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk — Base de Conhecimento: categorias (árvore).
 *  - visibility 'customer' → visível no Portal do Cliente.
 *  - visibility 'internal' → só atendentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helpdesk_kb_categories')) {
            return;
        }
        Schema::create('helpdesk_kb_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('helpdesk_kb_categories')->nullOnDelete();
            $table->string('name', 140);
            $table->string('slug', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('icon', 40)->nullable();
            $table->string('visibility', 12)->default('customer'); // customer | internal
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['parent_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_kb_categories');
    }
};
