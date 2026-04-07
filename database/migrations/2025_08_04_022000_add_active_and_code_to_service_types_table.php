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
        Schema::table('service_types', function (Blueprint $table) {
            $table->string('code')->nullable()->after('name');
            $table->boolean('active')->default(true)->after('description');
            
            // Índices
            $table->index('code');
            $table->index('active');
        });

        // Atualizar registros existentes com códigos
        DB::table('service_types')->where('name', 'Sustentação')->update(['code' => 'sustentacao']);
        DB::table('service_types')->where('name', 'Projeto')->update(['code' => 'projeto']);
        DB::table('service_types')->where('name', 'Investimento Comercial')->update(['code' => 'investimento_comercial']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->dropIndex(['code']);
            $table->dropIndex(['active']);
            $table->dropColumn(['code', 'active']);
        });
    }
};