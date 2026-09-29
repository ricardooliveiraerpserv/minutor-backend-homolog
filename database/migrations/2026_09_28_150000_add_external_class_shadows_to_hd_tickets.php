<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sombras do ÚLTIMO valor sincronizado do Movidesk para os campos de classificação.
 * Permitem o merge de 3 vias (saber qual lado mudou); no conflito, o Minutor vence e empurra
 * para o Movidesk (Fase 2 write-back). Guardam os valores CRUS do Movidesk (nomes/urgência).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $t) {
            foreach ([
                'external_category' => 120,
                'external_service'  => 255,
                'external_urgency'  => 40,
                'external_level'    => 20,
            ] as $col => $len) {
                if (!Schema::hasColumn('helpdesk_tickets', $col)) {
                    $t->string($col, $len)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $t) {
            $t->dropColumn(['external_category', 'external_service', 'external_urgency', 'external_level']);
        });
    }
};
