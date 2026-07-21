<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Respondente unificado da matriz — UMA matriz, TRÊS origens.
 *
 *  - internal  → colaborador (user_id preenchido; dados cadastrais vêm do user)
 *  - partner   → parceiro (partner_id opcional; dados em `data`)
 *  - candidate → Banco de Talentos (standalone, portal público, sem login)
 *
 * Mantém `users` limpo (candidatos externos não viram usuários). `data` guarda
 * os campos cadastrais específicos de cada tipo (valor hora, disponibilidade,
 * região, LinkedIn, currículo, pretensão, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_respondents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index()->comment('internal | partner | candidate');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->string('name', 160);
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->json('data')->nullable()->comment('campos cadastrais por tipo');
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_respondents');
    }
};
