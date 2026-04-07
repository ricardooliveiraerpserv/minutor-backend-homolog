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
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('parent_project_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('projects')
                ->onDelete('cascade')
                ->comment('ID do projeto pai (para subprojetos)');
            
            // Índice para melhorar performance nas consultas de hierarquia
            $table->index('parent_project_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['parent_project_id']);
            $table->dropIndex(['parent_project_id']);
            $table->dropColumn('parent_project_id');
        });
    }
};

