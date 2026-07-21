<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos estruturados no card de contratação: Cargo e Modalidade (PJ/Cooperado/
 * CLT). Alimentam a criação do usuário (contract_type + cargo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_hire_cards', function (Blueprint $table) {
            $table->string('cargo', 120)->nullable()->after('title');
            $table->string('modalidade', 20)->nullable()->after('cargo')->comment('pj | cooperado | clt');
        });
    }

    public function down(): void
    {
        Schema::table('skill_hire_cards', function (Blueprint $table) {
            $table->dropColumn(['cargo', 'modalidade']);
        });
    }
};
