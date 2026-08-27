<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kanban do Cliente — Fase 2: campos CONFIGURÁVEIS por quadro.
 * O cliente cria campos (texto/número/moeda/data/seleção/vínculo…) e cada card guarda
 * o valor em kanban_card_field_values (value TEXT; JSON p/ seleção múltipla).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kanban_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('kanban_boards')->cascadeOnDelete();
            $table->string('name');
            // text | textarea | number | money | date | datetime | select | multiselect | checkbox | link_user
            $table->string('type', 24);
            $table->boolean('required')->default(false);
            $table->boolean('show_on_front')->default(false);
            $table->json('options')->nullable();          // p/ select/multiselect: ["A","B",...]
            $table->string('default_value', 500)->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['board_id', 'position']);
        });

        Schema::create('kanban_card_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('kanban_cards')->cascadeOnDelete();
            $table->foreignId('field_id')->constrained('kanban_fields')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['card_id', 'field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_card_field_values');
        Schema::dropIfExists('kanban_fields');
    }
};
