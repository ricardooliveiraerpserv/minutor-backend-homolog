<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data de ENTREGA PREVISTA em homologação — obrigatória ao mover o chamado para "Em Desenvolvimento".
 * Vira legenda no chamado e é comunicada ao cliente pela interação do consultor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('helpdesk_tickets', 'dev_delivery_at')) {
                $table->date('dev_delivery_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            if (Schema::hasColumn('helpdesk_tickets', 'dev_delivery_at')) {
                $table->dropColumn('dev_delivery_at');
            }
        });
    }
};
