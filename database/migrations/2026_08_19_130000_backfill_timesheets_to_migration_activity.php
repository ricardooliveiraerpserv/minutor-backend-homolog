<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BACKFILL — vincula os apontamentos existentes (lançados ANTES do cronograma,
 * stage_delivery_id NULL) à "Atividade do projeto" criada na seed de migração
 * (2026_08_14_120000_seed_cronograma_for_projects_without_stages).
 *
 * Sem isso, os apontamentos ficam "sem atividade — lançados antes do cronograma"
 * e o card mostra Apont 0h (o effort_minutes_sum soma via stage_delivery_id).
 *
 * Regra (definida no merge, confirmada 2026-08-19): TODOS os apontamentos do
 * projeto (por project_id), inclusive de meses já fechados, herdam a única
 * atividade "Atividade do projeto" da migração. Idempotente (só stage_delivery_id NULL).
 *
 * Vale p/ dev2 (onde a seed já rodou) e p/ prod (roda depois da seed; a seed já
 * vincula inline, então aqui vira no-op).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('timesheets')
            || !Schema::hasTable('stage_deliveries')
            || !Schema::hasTable('project_stages')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE timesheets t
            SET stage_delivery_id = d.id,
                stage_id = d.stage_id
            FROM stage_deliveries d
            JOIN project_stages s ON s.id = d.stage_id AND s.deleted_at IS NULL
            WHERE d.title = 'Atividade do projeto'
              AND d.deleted_at IS NULL
              AND t.project_id = s.project_id
              AND t.stage_delivery_id IS NULL
              AND t.deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        // Dado herdado — sem reversão automática.
    }
};
