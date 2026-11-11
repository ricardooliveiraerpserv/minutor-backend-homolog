<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fonte ENVIADO PELO CLIENTE (via chamado/e-mail) reusa o motor da GMUD. origin='cliente' separa da
 * GMUD da ERPSERV (NULL = GMUD); source_attachment_ids = anexos do chamado usados; sender_label = quem enviou.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE gmud_packages ADD COLUMN IF NOT EXISTS origin varchar(20) NULL');
        DB::statement('ALTER TABLE gmud_packages ADD COLUMN IF NOT EXISTS source_attachment_ids jsonb NULL');
        DB::statement('ALTER TABLE gmud_packages ADD COLUMN IF NOT EXISTS sender_label varchar(255) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE gmud_packages DROP COLUMN IF EXISTS sender_label');
        DB::statement('ALTER TABLE gmud_packages DROP COLUMN IF EXISTS source_attachment_ids');
        DB::statement('ALTER TABLE gmud_packages DROP COLUMN IF EXISTS origin');
    }
};
