<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * CRM Item 2 — probabilidade (%) por etapa do funil → habilita Probabilidade e
 * Forecast (valor × probabilidade) na lista de oportunidades. is_won=100, is_lost=0.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('crm_pipeline_stages', 'probabilidade')) {
            Schema::table('crm_pipeline_stages', function (Blueprint $table) {
                $table->unsignedTinyInteger('probabilidade')->default(0);
            });
        }

        // Seed por progressão dentro de cada funil (etapas intermediárias proporcionais).
        foreach (DB::table('crm_pipelines')->pluck('id') as $pid) {
            $stages = DB::table('crm_pipeline_stages')->where('pipeline_id', $pid)->orderBy('ordem')->get();
            $n = $stages->count();
            foreach ($stages->values() as $i => $s) {
                $prob = $s->is_won ? 100 : ($s->is_lost ? 0 : (int) round(($i + 1) / max($n, 1) * 80));
                DB::table('crm_pipeline_stages')->where('id', $s->id)->update(['probabilidade' => $prob]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_pipeline_stages', 'probabilidade')) {
            Schema::table('crm_pipeline_stages', fn (Blueprint $t) => $t->dropColumn('probabilidade'));
        }
    }
};
