<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Meus Processos" passa a valer para TODOS os perfis (inclusive agentes/internos, que
 * não têm customer_id). O quadro deixa de exigir cliente: customer_id vira nullable.
 * O acesso ao quadro é governado por criador + convites aceitos (não mais pelo customer).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE kanban_boards ALTER COLUMN customer_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Só volta a NOT NULL se não houver quadros de usuários internos (customer_id null).
        DB::statement('ALTER TABLE kanban_boards ALTER COLUMN customer_id SET NOT NULL');
    }
};
