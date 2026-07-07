<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Coluna acknowledged_at — usada pelo "confirmar recebimento" (ack) dos comunicados do cliente.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('communication_reads', 'acknowledged_at')) {
            Schema::table('communication_reads', function (Blueprint $table) {
                $table->timestamp('acknowledged_at')->nullable()->after('read_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('communication_reads', 'acknowledged_at')) {
            Schema::table('communication_reads', fn (Blueprint $table) => $table->dropColumn('acknowledged_at'));
        }
    }
};
