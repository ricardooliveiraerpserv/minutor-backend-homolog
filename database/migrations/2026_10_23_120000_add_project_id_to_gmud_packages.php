<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GMUD — Envio de Fontes de PROJETO (sem ticket). Vincula o pacote governado ao projeto de origem,
 * para que o envio fique documentado DENTRO do projeto (e não só no chamado automático). Idempotente.
 * Convenção da família source_docs: FK nullable, nullOnDelete (apagar o projeto não apaga o pacote/evidência).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gmud_packages') && ! Schema::hasColumn('gmud_packages', 'project_id')) {
            Schema::table('gmud_packages', function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->after('customer_id')
                    ->constrained('projects')->nullOnDelete();
                $table->index('project_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('gmud_packages') && Schema::hasColumn('gmud_packages', 'project_id')) {
            Schema::table('gmud_packages', function (Blueprint $table) {
                $table->dropConstrainedForeignId('project_id');
            });
        }
    }
};
