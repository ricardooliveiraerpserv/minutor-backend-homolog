<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // ALTER TYPE ... ADD VALUE não pode rodar dentro de transação no Postgres.
    public $withinTransaction = false;

    /**
     * Board "Demandas e Projetos" passa a ter as colunas:
     * Backlog → Em Planejamento → Em Andamento → Em Homologação → Em Produção → Pausado → Encerrado (+ Cancelado).
     * Adiciona os status 'planning' (Em Planejamento) e 'em_producao' (Em Produção) e normaliza
     * os nomes/ordem na tabela project_statuses (fonte das opções/validação).
     */
    public function up(): void
    {
        // 1) Valores novos no enum nativo (idempotente).
        DB::statement("ALTER TYPE projects_status ADD VALUE IF NOT EXISTS 'planning'");
        DB::statement("ALTER TYPE projects_status ADD VALUE IF NOT EXISTS 'em_producao'");

        // 2) Tabela project_statuses (code/name/is_active/sort_order) — ordem das colunas.
        $rows = [
            ['awaiting_start',       'Aguardando início', 1],
            ['planning',             'Em Planejamento',   2],
            ['started',              'Iniciado',          3],
            ['liberado_para_testes', 'Em Homologação',    4],
            ['em_producao',          'Em Produção',       5],
            ['paused',               'Pausado',           6],
            ['finished',             'Encerrado',         7],
            ['cancelled',            'Cancelado',         8],
        ];
        foreach ($rows as [$code, $name, $sort]) {
            $exists = DB::table('project_statuses')->where('code', $code)->exists();
            if ($exists) {
                DB::table('project_statuses')->where('code', $code)
                    ->update(['name' => $name, 'is_active' => true, 'sort_order' => $sort, 'updated_at' => now()]);
            } else {
                DB::table('project_statuses')->insert([
                    'code' => $code, 'name' => $name, 'is_active' => true, 'sort_order' => $sort,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Enum: remover valor não é trivial no Postgres — no-op. Só desativa as linhas novas.
        DB::table('project_statuses')->whereIn('code', ['planning', 'em_producao'])->update(['is_active' => false]);
    }
};
