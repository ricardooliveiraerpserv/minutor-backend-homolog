<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot do status "de espera com agendamento" (GMUD em Planejamento, Em Homologação,
 * Pendente terceiros, Em Desenvolvimento) guardado quando a RESPOSTA do cliente reativa o
 * chamado para "Em atendimento". Permite oferecer um botão "voltar ao status anterior com a
 * data" (restaura status + agendamento/SLA). Limpo ao restaurar ou mudar o status manualmente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('helpdesk_tickets', 'reactivation_snapshot')) {
                $table->json('reactivation_snapshot')->nullable()->after('dev_delivery_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            if (Schema::hasColumn('helpdesk_tickets', 'reactivation_snapshot')) {
                $table->dropColumn('reactivation_snapshot');
            }
        });
    }
};
