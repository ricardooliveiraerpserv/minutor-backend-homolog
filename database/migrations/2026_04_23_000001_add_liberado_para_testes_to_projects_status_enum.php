<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ALTER TYPE não pode rodar dentro de uma transação no PostgreSQL < 12.
// Mantemos $withinTransaction = false por segurança.
return new class extends Migration
{
    public bool $withinTransaction = false;

    public function up(): void
    {
        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_enum
                    JOIN pg_type ON pg_type.oid = pg_enum.enumtypid
                    WHERE pg_type.typname = 'projects_status'
                      AND pg_enum.enumlabel = 'liberado_para_testes'
                ) THEN
                    ALTER TYPE projects_status ADD VALUE 'liberado_para_testes';
                END IF;
            END
            $$;
        ");
    }

    public function down(): void
    {
        // PostgreSQL não suporta remoção de valores de ENUM sem recriar o tipo.
    }
};
