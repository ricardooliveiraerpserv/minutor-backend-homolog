<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cronograma — permissão de HORAS NEGATIVAS por ATIVIDADE (coordenador).
 *
 * Com o gating de apontamento ligado (cronograma_apontamento_gate), o consultor
 * só aponta até as horas liberadas da atividade (stage_allocations.planned_hours).
 * Esta flag deixa o COORDENADOR liberar o estouro NAQUELA atividade — o consultor
 * passa a poder apontar além do planejado. O teto do PROJETO continua valendo
 * (só o admin libera o projeto a ficar negativo via projects.allow_negative_balance).
 * Aditiva + idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stage_deliveries', 'allow_negative_hours')) {
            Schema::table('stage_deliveries', function (Blueprint $table) {
                $table->boolean('allow_negative_hours')->default(false)->after('hours_planned');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stage_deliveries', 'allow_negative_hours')) {
            Schema::table('stage_deliveries', function (Blueprint $table) {
                $table->dropColumn('allow_negative_hours');
            });
        }
    }
};
