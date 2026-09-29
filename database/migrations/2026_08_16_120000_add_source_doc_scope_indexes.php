<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * C4a — Índices de suporte ao SourceDocCustomerScope (resolver de escopo por cliente).
 *
 * O resolver descobre os clientes acessíveis de um usuário interno em TODA requisição da
 * Central de Fontes. Sem estes índices o EXPLAIN acusa Seq Scan em dois caminhos:
 *
 *   (A) customers WHERE executive_id = ?  /  executive_bizify_id = ?
 *       → caminho do executivo de conta (Customer::activeExecutiveColumn()).
 *       Índices parciais (IS NOT NULL) porque a maioria dos clientes não tem executivo.
 *       Beneficia também PermissionService::for (Customer::where('executive_id', ...)).
 *
 *   (B) project_consultant_groups WHERE consultant_group_id = ?
 *       → caminho do consultor via grupo. O UNIQUE existente é (project_id, consultant_group_id),
 *       que lidera por project_id e não serve para lookup por consultant_group_id.
 *
 * CONCURRENTLY pra não travar a tabela em produção (ver CLAUDE.md backend).
 * Não altera nenhum índice/constraint existente — apenas adiciona.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // (A) executivo de conta
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS customers_executive_id_idx ON customers (executive_id) WHERE executive_id IS NOT NULL');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS customers_executive_bizify_id_idx ON customers (executive_bizify_id) WHERE executive_bizify_id IS NOT NULL');

        // (B) consultor via grupo — sentido inverso do UNIQUE composto
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS project_consultant_groups_group_id_idx ON project_consultant_groups (consultant_group_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_executive_id_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_executive_bizify_id_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS project_consultant_groups_group_id_idx');
    }
};
