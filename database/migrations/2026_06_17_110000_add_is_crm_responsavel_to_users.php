<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * CRM — cadastro de Responsável Comercial: flag por usuário que o habilita como
 * responsável de oportunidade (forecast/metas/comissões). Substitui a heurística
 * type∈{admin,administrativo}|is_executive. Backfill mantém esses como responsáveis.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'is_crm_responsavel')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_crm_responsavel')->default(false);
            });
        }
        // Backfill: quem já era opção do select continua sendo responsável.
        DB::table('users')
            ->where(fn ($q) => $q->whereIn('type', ['admin', 'administrativo'])->orWhere('is_executive', true))
            ->update(['is_crm_responsavel' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'is_crm_responsavel')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_crm_responsavel'));
        }
    }
};
