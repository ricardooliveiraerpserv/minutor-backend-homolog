<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('initial_hours_balance', 10, 2)->nullable()->after('exceeded_hour_contribution')
                  ->comment('Saldo inicial de horas do projeto. Pode ser positivo ou negativo.');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('initial_hours_balance');
        });
    }
};
