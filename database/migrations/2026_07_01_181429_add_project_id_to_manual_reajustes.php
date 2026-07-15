<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula uma inclusão da rotina de reajuste a um PROJETO existente (sem contrato).
 * Quando preenchido, a linha deixa de ser "manual pura" e passa a representar o
 * reajuste do projeto: ao aplicar, atualiza o hourly_rate do projeto. Sem contrato,
 * sem card novo no pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_reajustes', function (Blueprint $table) {
            if (!Schema::hasColumn('manual_reajustes', 'project_id')) {
                $table->foreignId('project_id')->nullable()->after('customer_id')
                    ->constrained('projects')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('manual_reajustes', function (Blueprint $table) {
            if (Schema::hasColumn('manual_reajustes', 'project_id')) {
                $table->dropConstrainedForeignId('project_id');
            }
        });
    }
};
