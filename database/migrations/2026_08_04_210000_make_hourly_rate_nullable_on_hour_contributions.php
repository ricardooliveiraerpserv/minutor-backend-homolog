<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Aporte NÃO valorizado grava hourly_rate = null (só horas, sem valor). A migration
     * que adicionou `nao_valorizado` não tornou `hourly_rate` nullable → insert falhava
     * com NOT NULL violation. Idempotente (DROP NOT NULL é no-op se já nullable).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE hour_contributions ALTER COLUMN hourly_rate DROP NOT NULL');
    }

    public function down(): void
    {
        // Não re-impõe NOT NULL: existem aportes não valorizados com hourly_rate null.
    }
};
