<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('helpdesk_statuses', function (Blueprint $t) {
            // Quando true, ao escolher este status no chamado aparece o agendamento (data + hora opcional).
            $t->boolean('allows_scheduling')->default(false)->after('sla_paused');
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_statuses', function (Blueprint $t) {
            $t->dropColumn('allows_scheduling');
        });
    }
};
