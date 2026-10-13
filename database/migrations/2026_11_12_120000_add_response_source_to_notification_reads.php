<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origem da resposta da publicação: 'app' (Central/pop-up) ou 'email' (link de 1 clique).
 * Permite saber quem confirmou presença pelo e-mail vs. pelo sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_reads', function (Blueprint $table) {
            if (!Schema::hasColumn('notification_reads', 'response_source')) {
                $table->string('response_source', 16)->nullable()->after('response_action');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_reads', function (Blueprint $table) {
            if (Schema::hasColumn('notification_reads', 'response_source')) {
                $table->dropColumn('response_source');
            }
        });
    }
};
