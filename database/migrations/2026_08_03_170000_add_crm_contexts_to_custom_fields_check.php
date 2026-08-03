<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A migration original criou `context` como enum Laravel, que no Postgres
     * vira varchar + CHECK constraint `custom_fields_context_check` restrito a
     * Project/Timesheet/Expense/Customer. O CRM passou a usar os contextos
     * Opportunity (pipeline) e Contact (contatos) na validação/controller, mas
     * a constraint do banco nunca foi ampliada — o INSERT falhava com
     * SQLSTATE[23514]. Aqui recriamos a constraint incluindo os novos contextos.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE custom_fields DROP CONSTRAINT IF EXISTS custom_fields_context_check');
        DB::statement(
            "ALTER TABLE custom_fields ADD CONSTRAINT custom_fields_context_check "
            . "CHECK (context::text = ANY (ARRAY['Project','Timesheet','Expense','Customer','Opportunity','Contact']::text[]))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE custom_fields DROP CONSTRAINT IF EXISTS custom_fields_context_check');
        DB::statement(
            "ALTER TABLE custom_fields ADD CONSTRAINT custom_fields_context_check "
            . "CHECK (context::text = ANY (ARRAY['Project','Timesheet','Expense','Customer']::text[]))"
        );
    }
};
