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
        // Modificar o enum para incluir 'conflicted'
        // Rodar só no mysql, sqlite não suporta MODIFY COLUMN ou ENUM
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE timesheets MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'conflicted') DEFAULT 'pending'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Primeiro, atualizar todos os registros com status 'conflicted' para 'pending'
        DB::table('timesheets')
            ->where('status', 'conflicted')
            ->update(['status' => 'pending']);

        // Depois, remover 'conflicted' do enum
        DB::statement("ALTER TABLE timesheets MODIFY COLUMN status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'");
    }
};
