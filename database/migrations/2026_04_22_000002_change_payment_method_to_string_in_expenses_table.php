<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE expenses ALTER COLUMN payment_method TYPE VARCHAR(255)');
        } elseif ($driver !== 'sqlite') {
            Schema::table('expenses', function (Blueprint $table) {
                $table->string('payment_method')->change();
            });
        }
    }

    public function down(): void
    {
        // Não reverter: valores além do ENUM original podem existir nos dados
    }
};
