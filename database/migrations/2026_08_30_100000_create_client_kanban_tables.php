<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kanban do Cliente (Fase 1 / MVP). Quadros pertencem ao CLIENTE (customer_id),
 * independentes de projeto. O cliente cria/gerencia os próprios quadros, colunas e cards.
 * Anexos de card reusam a infra FASE 11 (entity_type = KANBAN_CARD).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kanban_boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color', 9)->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['customer_id', 'position']);
        });

        Schema::create('kanban_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('kanban_boards')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 9)->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->index(['board_id', 'position']);
        });

        Schema::create('kanban_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('kanban_boards')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 9)->nullable();
            $table->timestamps();
            $table->index('board_id');
        });

        Schema::create('kanban_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('kanban_boards')->cascadeOnDelete();
            $table->foreignId('column_id')->constrained('kanban_columns')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('priority', 20)->nullable(); // low | medium | high
            $table->integer('position')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['column_id', 'position']);
            $table->index('board_id');
        });

        Schema::create('kanban_card_label', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('kanban_cards')->cascadeOnDelete();
            $table->foreignId('label_id')->constrained('kanban_labels')->cascadeOnDelete();
            $table->unique(['card_id', 'label_id']);
        });

        Schema::create('kanban_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('kanban_cards')->cascadeOnDelete();
            $table->string('text');
            $table->boolean('is_done')->default(false);
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->index(['card_id', 'position']);
        });

        Schema::create('kanban_card_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('kanban_cards')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['card_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_card_comments');
        Schema::dropIfExists('kanban_checklist_items');
        Schema::dropIfExists('kanban_card_label');
        Schema::dropIfExists('kanban_cards');
        Schema::dropIfExists('kanban_labels');
        Schema::dropIfExists('kanban_columns');
        Schema::dropIfExists('kanban_boards');
    }
};
