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
        // Verificar se a coluna já existe antes de adicionar
        if (!Schema::hasColumn('projects', 'unlimited_expense')) {
            Schema::table('projects', function (Blueprint $table) {
                // Adicionar após max_expense_per_consultant (que foi criado na migration anterior)
                $table->boolean('unlimited_expense')->default(false)->after('max_expense_per_consultant');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('unlimited_expense');
        });
    }
};

