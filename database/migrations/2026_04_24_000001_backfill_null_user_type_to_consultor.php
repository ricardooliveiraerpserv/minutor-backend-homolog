<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Usuários criados após a migration original (2026-04-14) sem type explícito ficam NULL.
        // A intenção original era ELSE 'consultor' para todos sem role específica.
        DB::statement("UPDATE users SET type = 'consultor' WHERE type IS NULL");
    }

    public function down(): void
    {
        // Não reverter: não há como distinguir os que eram NULL dos que eram consultor intencionalmente
    }
};
