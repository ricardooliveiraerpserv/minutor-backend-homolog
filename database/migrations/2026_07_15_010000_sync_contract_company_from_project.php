<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Multi-empresa: o company_id do contrato deve seguir o do seu projeto.
 * Alguns projetos ficaram em uma empresa (ex.: BIZIFY) mas o contrato vinculado
 * permaneceu na outra (ERPSERV) — como o Kanban de Contratos escopa por
 * contract.company_id, esses contratos sumiam ao filtrar pela empresa do projeto.
 * Sincroniza contract.company_id ← project.company_id onde divergem. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Portável (correlated subquery) — evita UPDATE...FROM específico do PG.
        DB::statement("
            UPDATE contracts
            SET company_id = (
                SELECT p.company_id FROM projects p WHERE p.id = contracts.project_id
            ),
            updated_at = now()
            WHERE project_id IS NOT NULL
              AND deleted_at IS NULL
              AND EXISTS (
                  SELECT 1 FROM projects p
                  WHERE p.id = contracts.project_id
                    AND p.company_id IS NOT NULL
                    AND p.company_id IS DISTINCT FROM contracts.company_id
              )
        ");
    }

    public function down(): void
    {
        // Sem rollback: não há registro do company_id anterior do contrato.
        // (A sincronização é a correção correta; reverter recriaria a divergência.)
    }
};
