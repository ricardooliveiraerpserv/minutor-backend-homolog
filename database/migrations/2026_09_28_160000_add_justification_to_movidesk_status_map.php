<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O Movidesk EXIGE uma justificativa (reason) configurada ao trocar o status via API.
 * Guardamos, por status HD (linha de saída do de-para), qual justificativa do Movidesk usar.
 * Sem justificativa preenchida → o status NÃO é empurrado (fica só entrada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_movidesk_status_map', function (Blueprint $t) {
            if (!Schema::hasColumn('helpdesk_movidesk_status_map', 'movidesk_justification')) {
                $t->string('movidesk_justification', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_movidesk_status_map', function (Blueprint $t) {
            $t->dropColumn('movidesk_justification');
        });
    }
};
