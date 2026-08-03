<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adiciona o contexto Product (produtos/serviços do CRM) à check constraint
     * `custom_fields_context_check`. Segue o mesmo padrão idempotente da migration
     * que incluiu Opportunity/Contact.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE custom_fields DROP CONSTRAINT IF EXISTS custom_fields_context_check');
        DB::statement(
            "ALTER TABLE custom_fields ADD CONSTRAINT custom_fields_context_check "
            . "CHECK (context::text = ANY (ARRAY['Project','Timesheet','Expense','Customer','Opportunity','Contact','Product']::text[]))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE custom_fields DROP CONSTRAINT IF EXISTS custom_fields_context_check');
        DB::statement(
            "ALTER TABLE custom_fields ADD CONSTRAINT custom_fields_context_check "
            . "CHECK (context::text = ANY (ARRAY['Project','Timesheet','Expense','Customer','Opportunity','Contact']::text[]))"
        );
    }
};
