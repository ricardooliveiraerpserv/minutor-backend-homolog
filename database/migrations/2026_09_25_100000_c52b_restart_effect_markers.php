<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connector-5.2b — requires_restart (rolling). Dois marcadores de journal ESPECIALIZADOS, distintos do
 * genérico effect_started_at (que = publish || restart, o primeiro): publish_effect_started_at (o RPO pode
 * ter mudado em disco) e restart_effect_started_at (o processo pode ter sido reiniciado). Só evidência/
 * diagnóstico — NUNCA base de retry. Aditivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('connector_operations')) {
            return;
        }
        Schema::table('connector_operations', function (Blueprint $t) {
            if (! Schema::hasColumn('connector_operations', 'publish_effect_started_at')) {
                $t->timestamp('publish_effect_started_at')->nullable();
            }
            if (! Schema::hasColumn('connector_operations', 'restart_effect_started_at')) {
                $t->timestamp('restart_effect_started_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('connector_operations', function (Blueprint $t) {
            foreach (['publish_effect_started_at', 'restart_effect_started_at'] as $c) {
                if (Schema::hasColumn('connector_operations', $c)) { $t->dropColumn($c); }
            }
        });
    }
};
