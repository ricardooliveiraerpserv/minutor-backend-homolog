<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adiciona os contextos Task (tarefas do CRM) e Proposal (propostas do CRM) à
     * check constraint `custom_fields_context_check`. Mesmo padrão idempotente das
     * migrations anteriores (Opportunity/Contact e Product).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE custom_fields DROP CONSTRAINT IF EXISTS custom_fields_context_check');
        DB::statement(
            "ALTER TABLE custom_fields ADD CONSTRAINT custom_fields_context_check "
            . "CHECK (context::text = ANY (ARRAY['Project','Timesheet','Expense','Customer','Opportunity','Contact','Product','Task','Proposal']::text[]))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE custom_fields DROP CONSTRAINT IF EXISTS custom_fields_context_check');
        DB::statement(
            "ALTER TABLE custom_fields ADD CONSTRAINT custom_fields_context_check "
            . "CHECK (context::text = ANY (ARRAY['Project','Timesheet','Expense','Customer','Opportunity','Contact','Product']::text[]))"
        );
    }
};
