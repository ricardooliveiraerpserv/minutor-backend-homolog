<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integração Help Desk ↔ Movidesk (espelhamento Promax).
 * Marca a ORIGEM de cada interação e guarda o id da ação no Movidesk:
 *  - `source`: 'movidesk' (importada do Movidesk) | null (nativa do Minutor)
 *  - `external_action_id`: id da action do Movidesk → dedup no re-sync e guarda anti-eco.
 * IMPORTANTE: a integração é SOMENTE LEITURA no Movidesk — nada é escrito lá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_ticket_comments', function (Blueprint $t) {
            if (!Schema::hasColumn('helpdesk_ticket_comments', 'source')) {
                $t->string('source', 20)->nullable()->index();
            }
            if (!Schema::hasColumn('helpdesk_ticket_comments', 'external_action_id')) {
                $t->string('external_action_id', 40)->nullable();
            }
        });

        // Dedup rápido por origem+id externo (evita reimportar a mesma action).
        Schema::table('helpdesk_ticket_comments', function (Blueprint $t) {
            $t->index(['source', 'external_action_id'], 'idx_hd_comments_source_extid');
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_ticket_comments', function (Blueprint $t) {
            $t->dropIndex('idx_hd_comments_source_extid');
            $t->dropColumn(['source', 'external_action_id']);
        });
    }
};
