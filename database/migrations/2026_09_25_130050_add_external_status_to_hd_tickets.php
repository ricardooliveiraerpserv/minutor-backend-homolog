<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integração Movidesk (espelhamento Promax): guarda o ÚLTIMO status do Movidesk já importado,
 * para que o re-sync só reaplique o status no HD quando ele MUDAR no Movidesk — sem desfazer
 * a mudança de status que o atendente fez no Minutor. `external_synced_at` = último import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $t) {
            if (!Schema::hasColumn('helpdesk_tickets', 'external_status')) {
                $t->string('external_status', 120)->nullable();
            }
            if (!Schema::hasColumn('helpdesk_tickets', 'external_synced_at')) {
                $t->timestamp('external_synced_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $t) {
            $t->dropColumn(['external_status', 'external_synced_at']);
        });
    }
};
