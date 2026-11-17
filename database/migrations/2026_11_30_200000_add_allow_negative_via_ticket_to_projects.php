<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato de sustentação: permite apontar com saldo NEGATIVO somente via chamado (interação do
 * Help Desk). Default = permitido (decisão Ricardo 17/11/2026). Apontamento manual segue a regra
 * normal (allow_negative_balance).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('projects', 'allow_negative_via_ticket')) {
            Schema::table('projects', fn (Blueprint $t) => $t->boolean('allow_negative_via_ticket')->default(true));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('projects', 'allow_negative_via_ticket')) {
            Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('allow_negative_via_ticket'));
        }
    }
};
