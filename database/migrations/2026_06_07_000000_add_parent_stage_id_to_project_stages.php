<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub-etapas no cronograma: uma etapa pode ter etapa-mãe (parent_stage_id).
 * Estrutura de 3 níveis → Etapa (parent null) → Sub-etapa (parent setado) → Atividade.
 * Nullable + nullOnDelete (remover a etapa-mãe não apaga as sub-etapas — elas
 * voltam a ser etapas de topo, sem perda de dados).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('project_stages', function (Blueprint $table) {
            if (!Schema::hasColumn('project_stages', 'parent_stage_id')) {
                $table->foreignId('parent_stage_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('project_stages')
                    ->nullOnDelete();
                $table->index('parent_stage_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_stages', function (Blueprint $table) {
            if (Schema::hasColumn('project_stages', 'parent_stage_id')) {
                $table->dropConstrainedForeignId('parent_stage_id');
            }
        });
    }
};
