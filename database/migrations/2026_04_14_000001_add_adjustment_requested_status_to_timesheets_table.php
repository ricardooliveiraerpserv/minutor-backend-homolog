<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // No PostgreSQL, enum é um tipo nomeado — precisamos recriar com ALTER TYPE
            DB::statement("ALTER TYPE timesheets_status ADD VALUE IF NOT EXISTS 'conflicted'");
            DB::statement("ALTER TYPE timesheets_status ADD VALUE IF NOT EXISTS 'adjustment_requested'");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE timesheets MODIFY COLUMN status ENUM('pending','approved','rejected','conflicted','adjustment_requested') DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        // PostgreSQL não suporta remoção de valores de enum sem recriar o tipo
        // Apenas atualizamos os registros para um status válido
        DB::table('timesheets')
            ->where('status', 'adjustment_requested')
            ->update(['status' => 'pending']);
    }
};
