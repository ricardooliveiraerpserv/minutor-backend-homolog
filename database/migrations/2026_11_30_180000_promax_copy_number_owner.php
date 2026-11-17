<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cópia Promax no Movidesk: (1) o chamado do Minutor adota o NÚMERO do Movidesk — o antigo fica em
// legacy_ticket_number (e-mails já enviados continuam casando); (2) gatilho "chamado criado" fica
// ADIADO até a cópia existir (confirmação sai com o número final); (3) responsável/equipe do Movidesk.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $t) {
            if (!Schema::hasColumn('helpdesk_tickets', 'legacy_ticket_number')) $t->string('legacy_ticket_number', 40)->nullable()->index();
            if (!Schema::hasColumn('helpdesk_tickets', 'deferred_created_context')) $t->json('deferred_created_context')->nullable();
            if (!Schema::hasColumn('helpdesk_tickets', 'external_owner_name')) $t->string('external_owner_name', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $t) {
            $t->dropColumn(['legacy_ticket_number', 'deferred_created_context', 'external_owner_name']);
        });
    }
};
