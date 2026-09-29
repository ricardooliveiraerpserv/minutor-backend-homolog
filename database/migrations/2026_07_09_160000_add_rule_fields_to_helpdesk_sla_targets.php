<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 do SLA — campos por REGRA (target):
 *  - name: rótulo da regra (ex.: "ALTA").
 *  - enabled: regra desabilitada não é aplicada.
 *  - first_response_channels: canais de abertura que disparam o SLA de 1ª resposta
 *    (json de strings; vazio/null = todos).
 *  - pause_on_approval: pausa o SLA enquanto o ticket aguarda aprovação (workflow).
 *  - max_agent_actions: teto de ações do agente; ultrapassar encerra o SLA como vencido.
 *  - conditions: condições combinadas/independentes (json) — guardadas p/ fidelidade/futuro
 *    (o motor casa por prioridade, que cobre o contrato Cosan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_sla_targets', function (Blueprint $table) {
            if (!Schema::hasColumn('helpdesk_sla_targets', 'name')) {
                $table->string('name', 120)->nullable()->after('priority');
            }
            if (!Schema::hasColumn('helpdesk_sla_targets', 'enabled')) {
                $table->boolean('enabled')->default(true)->after('name');
            }
            if (!Schema::hasColumn('helpdesk_sla_targets', 'first_response_channels')) {
                $table->json('first_response_channels')->nullable()->after('resolution_minutes');
            }
            if (!Schema::hasColumn('helpdesk_sla_targets', 'pause_on_approval')) {
                $table->boolean('pause_on_approval')->default(false)->after('first_response_channels');
            }
            if (!Schema::hasColumn('helpdesk_sla_targets', 'max_agent_actions')) {
                $table->integer('max_agent_actions')->nullable()->after('pause_on_approval');
            }
            if (!Schema::hasColumn('helpdesk_sla_targets', 'conditions')) {
                $table->json('conditions')->nullable()->after('max_agent_actions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_sla_targets', function (Blueprint $table) {
            foreach (['conditions', 'max_agent_actions', 'pause_on_approval', 'first_response_channels', 'enabled', 'name'] as $col) {
                if (Schema::hasColumn('helpdesk_sla_targets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
