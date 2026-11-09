<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Número do chamado único POR EMPRESA (company_id, ticket_number), não mais global.
 * Motivo: chamados importados de contas Movidesk diferentes (ERPSERV × BIZIFY) mantêm o número
 * de origem, e os ids colidem entre as contas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS helpdesk_tickets_company_ticket_number_unique ON helpdesk_tickets (company_id, ticket_number)');
        DB::statement('ALTER TABLE helpdesk_tickets DROP CONSTRAINT IF EXISTS helpdesk_tickets_ticket_number_unique');
        DB::statement('DROP INDEX IF EXISTS helpdesk_tickets_ticket_number_unique');
        DB::statement('CREATE INDEX IF NOT EXISTS helpdesk_tickets_ticket_number_index ON helpdesk_tickets (ticket_number)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS helpdesk_tickets_company_ticket_number_unique');
        DB::statement('DROP INDEX IF EXISTS helpdesk_tickets_ticket_number_index');
        DB::statement('ALTER TABLE helpdesk_tickets ADD CONSTRAINT helpdesk_tickets_ticket_number_unique UNIQUE (ticket_number)');
    }
};
