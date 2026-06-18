<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM configurável — Fase 5. Arquivamento de pipeline (não-destrutivo) + trilha de
 * auditoria de configuração (crm_pipeline_events). Aditivo, sem impacto nos dados atuais.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('crm_pipelines', 'arquivado')) {
            Schema::table('crm_pipelines', fn (Blueprint $t) => $t->boolean('arquivado')->default(false));
        }
        if (!Schema::hasTable('crm_pipeline_events')) {
            Schema::create('crm_pipeline_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pipeline_id')->nullable()->constrained('crm_pipelines')->nullOnDelete();
                $table->unsignedBigInteger('stage_id')->nullable();
                $table->string('acao', 40);          // pipeline_criado | pipeline_alterado | pipeline_arquivado | etapa_criada | etapa_alterada | etapa_reordenada | etapa_inativada | automacao_criada | ...
                $table->string('descricao', 200)->nullable();
                $table->json('antes')->nullable();
                $table->json('depois')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();
                $table->index(['pipeline_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_pipeline_events');
        if (Schema::hasColumn('crm_pipelines', 'arquivado')) {
            Schema::table('crm_pipelines', fn (Blueprint $t) => $t->dropColumn('arquivado'));
        }
    }
};
