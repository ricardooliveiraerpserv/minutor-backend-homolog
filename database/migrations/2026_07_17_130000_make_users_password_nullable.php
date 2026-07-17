<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permite usuário SEM senha (pré-cadastro estilo Movidesk): a ingestão de e-mail
 * cria um usuário cliente identificado pelo domínio, mas SEM senha — ele não loga
 * até ser convidado (fase 1b). `verifyPassword` já devolve false p/ hash nulo, então
 * o login continua barrando esses usuários normalmente.
 *
 * SQL cru (não usa ->change(), que exigiria doctrine/dbal no Laravel 10).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
    }

    public function down(): void
    {
        // Não re-impõe NOT NULL: se já houver usuários pré-cadastrados (senha nula),
        // o ALTER falharia. Reverter exigiria backfill — deixado como no-op seguro.
    }
};
