<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('enabled')
                ->comment('Valor hora do usuário (opcional)');
            $table->enum('rate_type', ['hourly', 'monthly'])->default('hourly')->after('hourly_rate')
                ->comment('Tipo de valor: por hora ou fixo por mês');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['hourly_rate', 'rate_type']);
        });
    }
};
