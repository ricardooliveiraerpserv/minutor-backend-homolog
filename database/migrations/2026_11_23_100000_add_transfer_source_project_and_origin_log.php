<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastreio de origem da Transferência de Apontamentos — evolução:
 * - timesheets.transfer_source_project_id: o PROJETO de origem (antes da migração), hoje perdido
 *   (project_id é sobrescrito pelo destino). Permite mostrar "veio de cliente X / projeto Y".
 * - timesheet_origin_logs: LOG de atribuição de origem (quem/como/quando) — auditoria completa:
 *   automático na migração, retroativo (apply), por ticket Movidesk, heurística, ou MANUAL pelo user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (! Schema::hasColumn('timesheets', 'transfer_source_project_id')) {
                $table->unsignedBigInteger('transfer_source_project_id')->nullable()->after('transfer_source_customer_id');
            }
        });

        if (! Schema::hasTable('timesheet_origin_logs')) {
            Schema::create('timesheet_origin_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('timesheet_id')->index();
                $table->unsignedBigInteger('source_customer_id')->nullable();
                $table->unsignedBigInteger('source_project_id')->nullable();
                $table->unsignedBigInteger('rule_id')->nullable();
                $table->string('ticket')->nullable();
                $table->string('method', 20); // rule_auto | rule_apply | ticket | heuristic | manual | native
                $table->unsignedBigInteger('set_by')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_origin_logs');
        Schema::table('timesheets', function (Blueprint $table) {
            if (Schema::hasColumn('timesheets', 'transfer_source_project_id')) {
                $table->dropColumn('transfer_source_project_id');
            }
        });
    }
};
