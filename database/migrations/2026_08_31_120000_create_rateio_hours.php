<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rateio de HORAS: um "projeto de rateio" (ex.: SERVIDOR CLARA NET) recebe apontamentos e
 * distribui as horas para N projetos de destino (de qualquer cliente) como CONSUMO real
 * (apontamentos-filhos is_billable_only=true → contam no destino, não no pagamento do consultor).
 *
 * - projects.is_rateio: marca o projeto-servidor (some dos Kanbans).
 * - project_rateio_targets: destinos + % padrão de cada projeto de rateio.
 * - timesheets.rateio_source_timesheet_id: liga o filho ao apontamento de origem (recompute/delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('projects', 'is_rateio')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->boolean('is_rateio')->default(false)->after('is_investimento_comercial')
                    ->comment('Projeto de rateio de horas: apontamentos nele distribuem para os destinos. Não gera card no Kanban.');
            });
        }

        if (!Schema::hasTable('project_rateio_targets')) {
            Schema::create('project_rateio_targets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rateio_project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('target_project_id')->constrained('projects')->cascadeOnDelete();
                $table->decimal('percentual', 5, 2)->default(0);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->unique(['rateio_project_id', 'target_project_id']);
                $table->index('rateio_project_id');
            });
        }

        if (!Schema::hasColumn('timesheets', 'rateio_source_timesheet_id')) {
            Schema::table('timesheets', function (Blueprint $table) {
                $table->unsignedBigInteger('rateio_source_timesheet_id')->nullable()->after('real_project_id')
                    ->comment('Apontamento de ORIGEM (no projeto de rateio) que gerou este filho de distribuição.');
                $table->index('rateio_source_timesheet_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('timesheets', 'rateio_source_timesheet_id')) {
            Schema::table('timesheets', fn (Blueprint $t) => $t->dropColumn('rateio_source_timesheet_id'));
        }
        Schema::dropIfExists('project_rateio_targets');
        if (Schema::hasColumn('projects', 'is_rateio')) {
            Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('is_rateio'));
        }
    }
};
