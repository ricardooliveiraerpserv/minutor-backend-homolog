<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix Connector-2: a migração 2026_08_30_100000 já rodou em bases existentes SEM a coluna
 * observed_json (estado corrente observado gravado pelo ConnectorInventoryProcessor). Esta
 * migração idempotente acrescenta a coluna onde faltar. Sem secret/path — só estado observado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('connector_environment_state')
            && ! Schema::hasColumn('connector_environment_state', 'observed_json')) {
            Schema::table('connector_environment_state', function (Blueprint $t) {
                $t->jsonb('observed_json')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('connector_environment_state', 'observed_json')) {
            Schema::table('connector_environment_state', function (Blueprint $t) {
                $t->dropColumn('observed_json');
            });
        }
    }
};
