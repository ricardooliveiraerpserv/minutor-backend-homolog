<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Remove a constraint unique atual
            $table->dropUnique(['cgc']);
        });
        
        // No MySQL, criamos um índice único composto usando uma expressão
        // que trata deleted_at NULL como um valor único para cada CGC
        DB::statement('CREATE UNIQUE INDEX customers_cgc_unique_not_deleted ON customers (cgc, (deleted_at IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove o índice único condicional
        DB::statement('DROP INDEX customers_cgc_unique_not_deleted ON customers');
        
        Schema::table('customers', function (Blueprint $table) {
            // Restaura a constraint unique original
            $table->unique('cgc');
        });
    }
};
