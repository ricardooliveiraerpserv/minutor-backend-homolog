<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    // CREATE INDEX CONCURRENTLY não pode rodar dentro de transação.
    public $withinTransaction = false;

    public function up(): void
    {
        // Rede de segurança: se algum ambiente tiver >1 projeto flagado por cliente,
        // mantém só o de MENOR id e desliga os demais — senão a unique index falha.
        // (Em prod não há duplicata; este UPDATE não afeta nada lá.)
        DB::statement(<<<'SQL'
            UPDATE projects p
               SET movidesk_integration_enabled = false
             WHERE p.movidesk_integration_enabled = true
               AND p.deleted_at IS NULL
               AND p.id <> (
                   SELECT MIN(q.id) FROM projects q
                    WHERE q.customer_id = p.customer_id
                      AND q.movidesk_integration_enabled = true
                      AND q.deleted_at IS NULL
               )
        SQL);

        // Garantia FÍSICA: no máximo 1 projeto ativo (não soft-deletado) com a
        // integração Movidesk ligada por cliente. Blinda contra violação por
        // QUALQUER caminho (raw SQL, jobs, imports), não só pelo ProjectController.
        DB::statement(
            'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS uniq_movidesk_flag_per_customer '
            . 'ON projects (customer_id) '
            . 'WHERE movidesk_integration_enabled = true AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS uniq_movidesk_flag_per_customer');
    }
};
