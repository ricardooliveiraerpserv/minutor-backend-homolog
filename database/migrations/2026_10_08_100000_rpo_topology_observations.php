<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RPO-DISCOVERY (C5.0) D1 — observação de TOPOLOGIA física do ambiente Protheus, portada da lógica legada
 * comprovada (ini-parser/extractSlaveInfo/resolveRpoPath) para o Connector on-prem, sanitizada. É OBSERVAÇÃO
 * (realidade), NÃO capability (capacidade executável vive em connector_environment_state.rpo_capability).
 *
 * Congelado (contrato D1 APPROVED/FROZEN):
 *  - agrupamento por publish_unit_id (identidade opaca já existente; NÃO cria rpo_unit).
 *  - agent_observed_at = evidência do agente; backend_received_at = AUTORIDADE de freshness (server-side).
 *  - topology_revision atribuída pelo BACKEND (monotônica/ambiente); agente NÃO controla a sequência.
 *  - topology_fingerprint = hash do conjunto canônico (ref, publish_unit_id, role, role_source, env_name).
 *  - members SANITIZADOS: zero path/INI/SpecialKey/bytes/comando. Sem company_id (segue rpo_*).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rpo_topology_observations')) {
            Schema::create('rpo_topology_observations', function (Blueprint $t) {
                $t->id();
                $t->string('observation_id', 64)->nullable(); // id do agente (evidência); backend valida/gera
                $t->unsignedBigInteger('environment_id');
                $t->string('agent_id', 80)->nullable();        // connector_id
                $t->timestamp('agent_observed_at')->nullable(); // evidência temporal do host (pode ter skew)
                $t->timestamp('backend_received_at');           // AUTORIDADE de freshness (server-side)
                $t->unsignedInteger('topology_revision');       // monotônico por ambiente, atribuído pelo backend
                $t->string('topology_fingerprint', 64);         // hash do conjunto canônico
                $t->jsonb('members');                           // SANITIZADO (denylist)
                $t->timestamps();
                $t->index(['environment_id', 'backend_received_at']);
                $t->unique(['environment_id', 'topology_revision']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rpo_topology_observations');
    }
};
