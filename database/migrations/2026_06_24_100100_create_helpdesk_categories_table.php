<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk — Categorias de chamado (árvore). Classificação do ticket + roteamento
 * (fila padrão + política de SLA padrão da categoria). Tudo local, sem dependência externa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helpdesk_categories')) {
            return;
        }
        Schema::create('helpdesk_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('helpdesk_categories')->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140)->nullable();
            $table->text('description')->nullable();
            $table->string('color', 16)->nullable();
            $table->foreignId('default_team_id')->nullable();   // FK lógica → helpdesk_teams (criada depois)
            $table->foreignId('sla_policy_id')->nullable();      // FK lógica → helpdesk_sla_policies (criada depois)
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['parent_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_categories');
    }
};
