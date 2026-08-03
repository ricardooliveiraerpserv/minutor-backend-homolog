<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visibilidade por pipeline: define quais usuários podem ver cada pipeline do CRM.
     * Regra: só admin é bypass (vê todos); demais perfis ficam sujeitos a esta lista;
     * pipeline sem linha aqui fica visível só para admin.
     *
     * Para não travar acesso no deploy, semeia os pipelines EXISTENTES com todos os
     * usuários CRM ativos (is_crm_responsavel) — preserva o acesso atual. Pipelines
     * novos nascem admin-only até serem configurados.
     */
    public function up(): void
    {
        if (Schema::hasTable('crm_pipeline_user')) {
            return;
        }

        Schema::create('crm_pipeline_user', function (Blueprint $table) {
            $table->foreignId('pipeline_id')->constrained('crm_pipelines')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['pipeline_id', 'user_id']);
        });

        // Seed: preserva o acesso atual (todos viam todos os pipelines).
        $userIds = DB::table('users')->where('is_crm_responsavel', true)->pluck('id');
        $pipeIds = DB::table('crm_pipelines')->pluck('id');
        $rows = [];
        foreach ($pipeIds as $pid) {
            foreach ($userIds as $uid) {
                $rows[] = ['pipeline_id' => $pid, 'user_id' => $uid];
            }
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('crm_pipeline_user')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_pipeline_user');
    }
};
