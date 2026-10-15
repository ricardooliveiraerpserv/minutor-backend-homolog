<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * No modelo NOVO um participante pode ser EXTERNO (só e-mail, sem user_id). A tabela
 * `meeting_participants` nasceu como pivô (user_id NOT NULL) da Central antiga; aqui tornamos
 * user_id NULLABLE para aceitar participantes externos. Idempotente (DROP NOT NULL não erra se já nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE meeting_participants ALTER COLUMN user_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Não re-impõe NOT NULL (poderia já haver linhas com user_id nulo).
    }
};
