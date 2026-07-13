<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Apontamentos gerados por interações do Help Desk passam a ter origin='help_desk' (antes 'web'),
 * para aparecerem com a legenda "Help Desk" (em vez de "Movidesk"/"Web") no contrato e na tela de
 * apontamentos. Retroativo: converte os já existentes vinculados a um chamado de Help Desk.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('timesheets')
            ->whereNotNull('helpdesk_ticket_id')
            ->where(function ($q) {
                $q->where('origin', 'web')->orWhereNull('origin');
            })
            ->update(['origin' => 'help_desk']);
    }

    public function down(): void
    {
        DB::table('timesheets')->where('origin', 'help_desk')->update(['origin' => 'web']);
    }
};
