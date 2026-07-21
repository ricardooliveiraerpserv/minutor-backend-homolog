<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Banco de Competências — nível explícito "Nenhum conhecimento" (weight 0).
 *
 * O spec exige que toda competência tenha resposta obrigatória; quando o
 * respondente não conhece o assunto, marca "Nenhum conhecimento" (não pode
 * ficar em branco). Os níveis 1..4 (Básico/Intermediário/Avançado/Especialista)
 * continuam intactos — Gaps/Cobertura não são afetados.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('skill_levels')->where('weight', 0)->exists();
        if (! $exists) {
            DB::table('skill_levels')->insert([
                ['name' => 'Nenhum conhecimento', 'weight' => 0],
            ]);
        }
    }

    public function down(): void
    {
        DB::table('skill_levels')->where('weight', 0)->where('name', 'Nenhum conhecimento')->delete();
    }
};
