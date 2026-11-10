<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Equipe atual do chamado no Movidesk (ownerTeam), gravada no import. Decide, sem chamar a API,
 * se o chamado responde PELO Movidesk (equipe Promax) — ver App\Services\MovideskPushPolicy.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE helpdesk_tickets ADD COLUMN IF NOT EXISTS external_owner_team varchar(255) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE helpdesk_tickets DROP COLUMN IF EXISTS external_owner_team');
    }
};
