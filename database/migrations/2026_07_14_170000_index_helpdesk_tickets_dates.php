<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices de escala para a listagem de chamados: a fila ordena por updated_at e o Histórico
 * filtra/ordena por created_at. Sem eles, aos ~100K+ chamados a ordenação vira sort de tabela cheia.
 * CONCURRENTLY (não trava a tabela) → exige rodar FORA de transação.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS helpdesk_tickets_updated_at_index ON helpdesk_tickets (updated_at DESC)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS helpdesk_tickets_created_at_index ON helpdesk_tickets (created_at)');
        // Fila ativa: filtra por status aberto/terminal — índice parcial dos NÃO-mesclados por updated_at.
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS helpdesk_tickets_active_queue_index ON helpdesk_tickets (updated_at DESC) WHERE merged_into_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS helpdesk_tickets_updated_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS helpdesk_tickets_created_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS helpdesk_tickets_active_queue_index');
    }
};
