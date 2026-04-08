<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->unsignedBigInteger('movidesk_appointment_id')->nullable()->after('ticket');
        });

        // Índice único parcial: ignora NULLs (PostgreSQL)
        DB::statement(
            'CREATE UNIQUE INDEX timesheets_movidesk_appt_id_unique
             ON timesheets (movidesk_appointment_id)
             WHERE movidesk_appointment_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS timesheets_movidesk_appt_id_unique');

        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropColumn('movidesk_appointment_id');
        });
    }
};
