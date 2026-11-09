<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Empresa de ORIGEM do chamado (de onde veio o número). O número NÃO muda quando o chamado é
 * transferido para outra empresa (o cliente não pode se confundir) → unicidade por
 * (origin_company_id, ticket_number). Trigger garante origem = empresa na criação (inclusive inserts crus).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE helpdesk_tickets ADD COLUMN IF NOT EXISTS origin_company_id bigint NULL');
        DB::statement('UPDATE helpdesk_tickets SET origin_company_id = company_id WHERE origin_company_id IS NULL');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION helpdesk_tickets_origin_company_default() RETURNS trigger AS $$
            BEGIN
                IF NEW.origin_company_id IS NULL THEN NEW.origin_company_id := NEW.company_id; END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_helpdesk_tickets_origin_company ON helpdesk_tickets');
        DB::statement('CREATE TRIGGER trg_helpdesk_tickets_origin_company BEFORE INSERT OR UPDATE ON helpdesk_tickets FOR EACH ROW EXECUTE FUNCTION helpdesk_tickets_origin_company_default()');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS helpdesk_tickets_origin_ticket_number_unique ON helpdesk_tickets (origin_company_id, ticket_number)');
        DB::statement('DROP INDEX IF EXISTS helpdesk_tickets_company_ticket_number_unique');
        DB::statement('CREATE INDEX IF NOT EXISTS helpdesk_tickets_origin_company_id_index ON helpdesk_tickets (origin_company_id)');
    }

    public function down(): void
    {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS helpdesk_tickets_company_ticket_number_unique ON helpdesk_tickets (company_id, ticket_number)');
        DB::statement('DROP INDEX IF EXISTS helpdesk_tickets_origin_ticket_number_unique');
        DB::statement('DROP TRIGGER IF EXISTS trg_helpdesk_tickets_origin_company ON helpdesk_tickets');
        DB::statement('DROP FUNCTION IF EXISTS helpdesk_tickets_origin_company_default()');
        DB::statement('ALTER TABLE helpdesk_tickets DROP COLUMN IF EXISTS origin_company_id');
    }
};
