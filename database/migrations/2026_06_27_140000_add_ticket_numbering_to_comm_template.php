<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numeração de chamados configurável (formato Movidesk: 000000). prefix + padding + sequência
 * (contador). O "iniciador" define o próximo número. Guardado na config singleton do Help Desk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_comm_template', function (Blueprint $table) {
            $table->string('ticket_prefix', 10)->default('');     // ex.: '' ou 'HD-'
            $table->unsignedTinyInteger('ticket_padding')->default(6);
            $table->unsignedBigInteger('ticket_sequence')->default(0); // último número emitido
        });

        // Continuidade: começa do maior id já existente p/ não colidir com os chamados atuais.
        $maxId = (int) (DB::table('helpdesk_tickets')->max('id') ?? 0);
        DB::table('helpdesk_comm_template')->update(['ticket_sequence' => $maxId, 'ticket_padding' => 6, 'ticket_prefix' => '']);
    }

    public function down(): void
    {
        Schema::table('helpdesk_comm_template', function (Blueprint $table) {
            $table->dropColumn(['ticket_prefix', 'ticket_padding', 'ticket_sequence']);
        });
    }
};
