<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * CRM configurável — Fase 2. Regras de transição POR ETAPA (campos obrigatórios para
 * ENTRAR na etapa), data-driven. Backfill preserva o comportamento atual:
 * etapas "Proposta" passam a exigir produto (era hardcoded no moveStage).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('crm_pipeline_stages', 'regras')) {
            Schema::table('crm_pipeline_stages', fn (Blueprint $t) => $t->json('regras')->nullable());
        }
        // Preserva a regra hardcoded: etapa Proposta exige produto.
        DB::table('crm_pipeline_stages')->whereRaw("lower(name) like '%proposta%'")
            ->update(['regras' => json_encode(['produto'])]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_pipeline_stages', 'regras')) {
            Schema::table('crm_pipeline_stages', fn (Blueprint $t) => $t->dropColumn('regras'));
        }
    }
};
