<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mapa de migração: colunas antigas → novas
        $map = [
            'novo'        => 'backlog',
            'em_cadastro' => 'em_planejamento',
            'pronto'      => 'aprovado',
            'alocado'     => 'alocado', // mantém — projetos gerados ficam nessa coluna
        ];

        foreach ($map as $old => $new) {
            if ($old !== $new) {
                DB::table('contracts')
                    ->where('kanban_status', $old)
                    ->update(['kanban_status' => $new]);
            }
        }
    }

    public function down(): void
    {
        $map = [
            'backlog'         => 'novo',
            'novo_projeto'    => 'novo',
            'em_planejamento' => 'em_cadastro',
            'em_validacao'    => 'em_cadastro',
            'em_revisao'      => 'em_cadastro',
            'aprovado'        => 'pronto',
        ];

        foreach ($map as $new => $old) {
            DB::table('contracts')
                ->where('kanban_status', $new)
                ->update(['kanban_status' => $old]);
        }
    }
};
