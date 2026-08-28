<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liberação do consultor em 2 estágios para apontamentos de ATRASO da integração.
 *
 * Ao aprovar um atraso (entrar no período / mudar data de digitação), o apontamento
 * passa a contar para o CLIENTE, mas NÃO para o consultor (pagamento + banco de horas)
 * até um coordenador/admin "liberar as horas do consultor".
 *
 * - consultant_released: portão do pagamento/banco. DEFAULT true → itens normais
 *   (todos os existentes) continuam contando; só o atraso aprovado vira false.
 * - late_approved_at: marca que o item passou pela aprovação de atraso (dirige as abas).
 * - consultant_released_at / consultant_released_by: auditoria da liberação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (!Schema::hasColumn('timesheets', 'consultant_released')) {
                $table->boolean('consultant_released')->default(true)->after('date_locked');
            }
            if (!Schema::hasColumn('timesheets', 'late_approved_at')) {
                $table->timestamp('late_approved_at')->nullable()->after('consultant_released');
            }
            if (!Schema::hasColumn('timesheets', 'consultant_released_at')) {
                $table->timestamp('consultant_released_at')->nullable()->after('late_approved_at');
            }
            if (!Schema::hasColumn('timesheets', 'consultant_released_by')) {
                $table->unsignedBigInteger('consultant_released_by')->nullable()->after('consultant_released_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            foreach (['consultant_released_by', 'consultant_released_at', 'late_approved_at', 'consultant_released'] as $col) {
                if (Schema::hasColumn('timesheets', $col)) $table->dropColumn($col);
            }
        });
    }
};
