<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige a CHECK constraint projects_status_check (Postgres) para incluir TODOS os
 * status usados pelo app: backlog, awaiting_start, planning, started,
 * liberado_para_testes, em_producao, paused, cancelled, finished.
 *
 * A migration do cronograma (add_planning_producao_project_statuses) alterava um TYPE
 * enum (projects_status) que o ambiente de PRODUÇÃO não usa — lá a coluna é VARCHAR com
 * CHECK constraint — então planning/em_producao/backlog ficavam barrados no banco e o
 * "mover card" em Demandas e Projetos quebrava. Defensiva: só age se a constraint existir.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne("SELECT 1 FROM pg_constraint WHERE conname = 'projects_status_check'");
        if (!$exists) return; // ambiente com enum/sem check: nada a fazer

        DB::statement('ALTER TABLE projects DROP CONSTRAINT projects_status_check');
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK (status::text = ANY (ARRAY['backlog','awaiting_start','planning','started','liberado_para_testes','em_producao','paused','cancelled','finished']::text[]))");
    }

    public function down(): void
    {
        // Sem rollback: não voltar pra uma lista incompleta que quebra o kanban.
    }
};
