<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Público-alvo por mensagem da conversa da atividade.
 *
 * `audiences` = subconjunto de ['cliente','consultor']. Admin e coordenador veem
 * TUDO sempre. Se vazio/null → só admin/coordenador. Cliente marcado → o cliente
 * do projeto vê; consultor marcado → consultores veem.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('stage_activity_events', function (Blueprint $table) {
            $table->jsonb('audiences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stage_activity_events', function (Blueprint $table) {
            $table->dropColumn('audiences');
        });
    }
};
