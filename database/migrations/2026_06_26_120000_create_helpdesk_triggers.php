<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gatilhos (automação no-code) do Help Desk: QUANDO (evento + condições) → ENTÃO (ações).
 * Permite criar regras sem desenvolvimento — notificar, mudar status, alterar campo, tags, atribuir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_triggers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->boolean('enabled')->default(true);
            $table->string('event', 40);                       // ticket_created | status_changed | comment_added | assigned | field_changed | idle_in_status
            $table->string('condition_logic', 4)->default('all'); // all | any
            $table->json('conditions')->nullable();            // [{field, operator, value}]
            $table->json('actions')->nullable();               // [{type, params:{}}]
            $table->string('recipe', 60)->nullable();          // receita que gerou (p/ exibir/editar fácil)
            $table->unsignedInteger('run_order')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['event', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_triggers');
    }
};
