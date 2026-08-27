<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Participante de campanha "removido" = DESABILITADO (soft), não apagado — pode ser
 * reabilitado (pedido do Ricardo). disabled_at NULL = ativo; preenchido = desabilitado
 * (fora das contagens/lembretes, mas mantido p/ habilitar de volta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_survey_invites', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('last_reminder_at');
        });
    }

    public function down(): void
    {
        Schema::table('skill_survey_invites', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
