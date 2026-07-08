<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk — Filas/Equipes de atendimento + membros. A fila roteia o chamado;
 * membros são usuários internos do Minutor (sem cadastro externo).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('helpdesk_teams')) {
            Schema::create('helpdesk_teams', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 140)->nullable();
                $table->text('description')->nullable();
                $table->string('color', 16)->nullable();
                $table->foreignId('lead_user_id')->nullable()->constrained('users')->nullOnDelete(); // responsável da fila
                $table->boolean('active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['active', 'sort_order']);
            });
        }

        if (!Schema::hasTable('helpdesk_team_user')) {
            Schema::create('helpdesk_team_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('helpdesk_team_id')->constrained('helpdesk_teams')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role', 30)->nullable(); // agent | lead
                $table->timestamps();
                $table->unique(['helpdesk_team_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_team_user');
        Schema::dropIfExists('helpdesk_teams');
    }
};
