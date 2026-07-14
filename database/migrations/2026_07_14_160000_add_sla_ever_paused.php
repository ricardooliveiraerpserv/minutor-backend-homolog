<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalização de performance: marca se o chamado JÁ passou por algum status que pausa o SLA.
 * A listagem/kanban carrega os eventos (p/ reconstruir a pausa) SÓ desses tickets — a maioria nunca
 * pausou, então a query de eventos e o cálculo por ticket caem muito. NÃO muda o resultado do SLA
 * (é over-approximação segura: no máximo carrega eventos de quem no fim não pausou).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('helpdesk_tickets', 'sla_ever_paused')) {
                $table->boolean('sla_ever_paused')->default(false);
            }
        });

        // Backfill: marca quem está OU já esteve em status pausante (por evento status_changed).
        $pausingKeys = DB::table('helpdesk_statuses')->where('sla_paused', true)->pluck('key')->all();
        $pausingIds  = DB::table('helpdesk_statuses')->where('sla_paused', true)->pluck('id')->all();
        if (empty($pausingKeys)) return;

        if (!empty($pausingIds)) {
            DB::table('helpdesk_tickets')->whereIn('status_id', $pausingIds)->update(['sla_ever_paused' => true]);
        }

        DB::table('helpdesk_tickets')
            ->whereIn('id', function ($q) use ($pausingKeys) {
                $q->select('ticket_id')->from('helpdesk_ticket_events')
                  ->where('event_type', 'status_changed')->whereIn('to_value', $pausingKeys);
            })
            ->update(['sla_ever_paused' => true]);
    }

    public function down(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            if (Schema::hasColumn('helpdesk_tickets', 'sla_ever_paused')) {
                $table->dropColumn('sla_ever_paused');
            }
        });
    }
};
