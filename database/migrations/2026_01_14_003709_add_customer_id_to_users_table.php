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
            $table->foreignId('customer_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('customers')
                  ->onDelete('set null')
                  ->comment('ID do cliente associado ao usuário (null para usuários internos)');
            
            $table->index('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
