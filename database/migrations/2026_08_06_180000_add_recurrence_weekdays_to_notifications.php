<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Recorrência SEMANAL: dias da semana em que o aviso é re-disparado (0=domingo … 6=sábado). */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('notifications_center', 'recurrence_weekdays')) {
            Schema::table('notifications_center', function (Blueprint $t) {
                $t->json('recurrence_weekdays')->nullable()->after('recurrence_value');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('notifications_center', 'recurrence_weekdays')) {
            Schema::table('notifications_center', fn (Blueprint $t) => $t->dropColumn('recurrence_weekdays'));
        }
    }
};
