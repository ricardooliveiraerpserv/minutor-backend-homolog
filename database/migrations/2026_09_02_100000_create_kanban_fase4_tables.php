<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kanban do Cliente — Fase 4:
 *  - kanban_card_events: auditoria/histórico (criação, movimentação entre colunas, edição…)
 *  - kanban_board_members: acesso ao quadro por usuário (sem membros = todos do customer)
 *  - kanban_card_members: participantes do card
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kanban_card_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('kanban_boards')->cascadeOnDelete();
            $table->foreignId('card_id')->nullable()->constrained('kanban_cards')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);                        // created | moved | updated | comment | checklist | attachment | deleted
            $table->foreignId('from_column_id')->nullable();
            $table->foreignId('to_column_id')->nullable();
            $table->string('card_title')->nullable();          // snapshot p/ o histórico mesmo após excluir
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['board_id', 'created_at']);
            $table->index(['card_id', 'created_at']);
        });

        Schema::create('kanban_board_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('kanban_boards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['board_id', 'user_id']);
        });

        Schema::create('kanban_card_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('kanban_cards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['card_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_card_members');
        Schema::dropIfExists('kanban_board_members');
        Schema::dropIfExists('kanban_card_events');
    }
};
