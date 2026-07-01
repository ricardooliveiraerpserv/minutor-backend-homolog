<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Os novos status Proposal-Centric (aguardando_liberacao=20, liberado_execucao=17) excedem o
 * varchar(16) original. Alarga para varchar(32) preservando default/nullable (SQL puro).
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE crm_proposals ALTER COLUMN status TYPE varchar(32)');
        }
    }

    public function down(): void
    {
        // Não estreitar de volta (poderia truncar dados existentes).
    }
};
