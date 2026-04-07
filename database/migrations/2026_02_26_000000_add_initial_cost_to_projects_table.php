<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('initial_cost', 15, 2)->nullable()->after('initial_hours_balance')
                  ->comment('Custo inicial do projeto (ex: custos pré-projeto, taxas de entrada, etc.).');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('initial_cost');
        });
    }
};
