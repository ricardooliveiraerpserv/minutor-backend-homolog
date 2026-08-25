<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Fixar" (pin) — máx 2 por escopo — também no Diário/Comentários do projeto
 * (project_messages), usado por ProjectMessages em Gestão de Projetos,
 * Sustentação e no drawer do pipeline (aba Diário).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_messages') && ! Schema::hasColumn('project_messages', 'pinned_at')) {
            Schema::table('project_messages', function (Blueprint $table) {
                $table->timestamp('pinned_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('project_messages') && Schema::hasColumn('project_messages', 'pinned_at')) {
            Schema::table('project_messages', function (Blueprint $table) {
                $table->dropColumn('pinned_at');
            });
        }
    }
};
