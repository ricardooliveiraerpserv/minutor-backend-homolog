<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * CRM configurável — Fase 1. Campos de configuração de pipeline/etapa + flag `tipo`
 * (comercial | qualificacao) para eliminar o tratamento hardcoded do funil de leads.
 * Aditivo; preserva os dados/comportamento atuais via backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_pipelines', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_pipelines', 'descricao')) $table->string('descricao', 200)->nullable();
            if (!Schema::hasColumn('crm_pipelines', 'cor'))       $table->string('cor', 16)->nullable();
            if (!Schema::hasColumn('crm_pipelines', 'bloqueado')) $table->boolean('bloqueado')->default(false);
            if (!Schema::hasColumn('crm_pipelines', 'tipo'))      $table->string('tipo', 20)->default('comercial'); // comercial | qualificacao
        });

        Schema::table('crm_pipeline_stages', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_pipeline_stages', 'cor'))        $table->string('cor', 16)->nullable();
            if (!Schema::hasColumn('crm_pipeline_stages', 'sla_dias'))   $table->integer('sla_dias')->nullable();
            if (!Schema::hasColumn('crm_pipeline_stages', 'is_inicial')) $table->boolean('is_inicial')->default(false);
            if (!Schema::hasColumn('crm_pipeline_stages', 'ativa'))      $table->boolean('ativa')->default(true);
        });

        // Backfill: funil de qualificação → tipo=qualificacao; demais comercial.
        DB::table('crm_pipelines')->where('code', 'qualificacao')->update(['tipo' => 'qualificacao']);
        DB::table('crm_pipelines')->where('code', '!=', 'qualificacao')->update(['tipo' => 'comercial']);
        // Etapa inicial = a de menor ordem em cada pipeline (que não é ganho/perda).
        foreach (DB::table('crm_pipelines')->pluck('id') as $pid) {
            $first = DB::table('crm_pipeline_stages')->where('pipeline_id', $pid)
                ->where('is_won', false)->where('is_lost', false)->orderBy('ordem')->first();
            if ($first) DB::table('crm_pipeline_stages')->where('id', $first->id)->update(['is_inicial' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('crm_pipelines', function (Blueprint $table) {
            foreach (['descricao', 'cor', 'bloqueado', 'tipo'] as $c) if (Schema::hasColumn('crm_pipelines', $c)) $table->dropColumn($c);
        });
        Schema::table('crm_pipeline_stages', function (Blueprint $table) {
            foreach (['cor', 'sla_dias', 'is_inicial', 'ativa'] as $c) if (Schema::hasColumn('crm_pipeline_stages', $c)) $table->dropColumn($c);
        });
    }
};
