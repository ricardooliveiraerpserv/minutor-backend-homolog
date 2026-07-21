<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renomeia os níveis de senioridade para a nomenclatura da matriz antiga
 * (Analista Jr/Pleno/Sênior): Básico→Júnior, Intermediário→Pleno, Avançado→Sênior.
 * Especialista e "Nenhum conhecimento" ficam como estão. O peso (weight) não muda,
 * então respostas, médias e gaps continuam iguais — só o rótulo exibido.
 */
return new class extends Migration
{
    private const MAP = [
        1 => 'Júnior',
        2 => 'Pleno',
        3 => 'Sênior',
    ];

    private const OLD = [
        1 => 'Básico',
        2 => 'Intermediário',
        3 => 'Avançado',
    ];

    public function up(): void
    {
        foreach (self::MAP as $weight => $name) {
            DB::table('skill_levels')->where('weight', $weight)->update(['name' => $name]);
        }
    }

    public function down(): void
    {
        foreach (self::OLD as $weight => $name) {
            DB::table('skill_levels')->where('weight', $weight)->update(['name' => $name]);
        }
    }
};
