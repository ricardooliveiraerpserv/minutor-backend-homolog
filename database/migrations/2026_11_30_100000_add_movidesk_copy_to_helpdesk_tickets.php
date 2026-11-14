<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Cópia no Movidesk de chamado Promax aberto no Minutor (via suporte@) — ver MovideskPromaxCopyService. */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE helpdesk_tickets ADD COLUMN IF NOT EXISTS movidesk_copy_id varchar(40) NULL');
        DB::statement('ALTER TABLE helpdesk_tickets ADD COLUMN IF NOT EXISTS movidesk_copy_status varchar(120) NULL');
        DB::statement('ALTER TABLE helpdesk_tickets ADD COLUMN IF NOT EXISTS movidesk_copy_at timestamp(0) NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS helpdesk_tickets_movidesk_copy_id_index ON helpdesk_tickets (movidesk_copy_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS helpdesk_tickets_movidesk_copy_id_index');
        DB::statement('ALTER TABLE helpdesk_tickets DROP COLUMN IF EXISTS movidesk_copy_at');
        DB::statement('ALTER TABLE helpdesk_tickets DROP COLUMN IF EXISTS movidesk_copy_status');
        DB::statement('ALTER TABLE helpdesk_tickets DROP COLUMN IF EXISTS movidesk_copy_id');
    }
};
