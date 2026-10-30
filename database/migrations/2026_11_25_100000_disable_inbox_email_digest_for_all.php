<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Digest de "mensagens não lidas por email" (chat interno): por decisão de produto,
 * desligar para TODOS os usuários e fazer novos usuários nascerem DESLIGADOS.
 *  - inbox_email_disabled = true  => NÃO recebe o digest.
 *  - altera o DEFAULT da coluna para true (novos registros nascem desligados).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'inbox_email_disabled')) {
            return;
        }
        // Novos usuários nascem desligados.
        DB::statement('ALTER TABLE users ALTER COLUMN inbox_email_disabled SET DEFAULT true');
        // Desliga para todos os usuários existentes.
        DB::table('users')->update(['inbox_email_disabled' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'inbox_email_disabled')) {
            return;
        }
        // Reverte apenas o default (não há como restaurar as escolhas individuais anteriores).
        DB::statement('ALTER TABLE users ALTER COLUMN inbox_email_disabled SET DEFAULT false');
    }
};
