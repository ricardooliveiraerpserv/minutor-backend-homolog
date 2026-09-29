<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nível de atendimento do chamado (N1/N2/N3…) — estilo Movidesk. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('helpdesk_tickets', 'level')) {
                $table->string('level', 8)->nullable()->after('priority');
            }
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            if (Schema::hasColumn('helpdesk_tickets', 'level')) {
                $table->dropColumn('level');
            }
        });
    }
};
