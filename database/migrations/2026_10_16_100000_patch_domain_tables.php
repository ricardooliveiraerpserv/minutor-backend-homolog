<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PATCH P1 — FUNDAÇÃO do domínio Patch (produção GOVERNADA de artefato; Patch NÃO publica RPO — C5 publica).
 * Domínio PRÓPRIO (não refatora/generaliza C6). Boundary: PATCH INPUT → REQUEST → EXECUTION → CANDIDATE → C5 register.
 * P1 NÃO aplica patch, NÃO executa, NÃO registra no C5 — só contratos/persistência segura.
 *
 * Freezes: workspace_unit_id OPACO/agent-derived (nunca path/hash-de-path); UNIQUE ACTIVE por workspace_unit
 * (cross-producer compile|patch); base_rpo_hash congelado no request; batch order IMUTÁVEL; zero PTM bytes/path/INI.
 * Causalidade (execution_id+workspace_unit_id+batch_digest+journal+candidate_digest) preparada p/ P2. Sem company_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── PatchInput — identidade LÓGICA do .ptm. Zero bytes/path (agente resolve on-prem via source_ref opaco). ──
        if (! Schema::hasTable('patch_inputs')) {
            Schema::create('patch_inputs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('environment_id');
                $t->unsignedBigInteger('customer_id')->nullable(); // revalidado server-side, nunca autoridade
                $t->string('patch_id', 120);        // identidade lógica opaca
                $t->string('source_ref', 200)->nullable(); // ponteiro OPACO on-prem (fixture/simulated/live futuro) — NUNCA path
                $t->string('digest', 64);           // sha256 do .ptm (calculado on-prem)
                $t->string('provenance', 300)->nullable();
                $t->string('version', 60)->nullable();
                $t->string('release', 60)->nullable();
                $t->jsonb('compatibility')->nullable(); // appserver version/release alvo
                $t->string('classification', 16)->nullable(); // test|demo|operational (não relaxa segurança)
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['environment_id', 'customer_id']);
                $t->index('digest');
            });
        }

        // ── PatchRequest — intenção: aplicar um LOTE ordenado sobre uma base RPO comprovada, num modo. ──
        if (! Schema::hasTable('patch_requests')) {
            Schema::create('patch_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('environment_id');
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->string('base_rpo_hash', 64);    // CONGELADO no request (from_hash equivalente)
                $t->string('execution_mode', 12);   // fixture | simulated | live (sem fallback)
                $t->string('workspace_unit_id', 80)->nullable(); // OPACO/agent-derived (nunca path); alvo físico
                $t->unsignedInteger('capability_contract_version')->nullable();
                $t->string('batch_digest', 64)->nullable(); // digest do lote (dos item_digests ordenados)
                $t->string('classification', 16)->nullable();
                $t->string('status', 20)->default('open'); // open | executing | completed | failed | canceled
                $t->uuid('correlation_id');
                $t->unsignedBigInteger('requested_by')->nullable();
                $t->timestamp('requested_at')->nullable();
                $t->timestamps();
                $t->index(['environment_id', 'status']);
            });
        }

        // ── PatchRequestItem — lote ORDENADO e IMUTÁVEL (ordem + digest por item). ──
        if (! Schema::hasTable('patch_request_items')) {
            Schema::create('patch_request_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('patch_request_id');
                $t->unsignedBigInteger('patch_input_id');
                $t->unsignedInteger('batch_order');  // ordem imutável
                $t->string('item_digest', 64);       // digest do patch (pin do input no momento da request)
                $t->timestamps();
                $t->unique(['patch_request_id', 'batch_order']);        // ordem única
                $t->unique(['patch_request_id', 'patch_input_id']);     // sem duplicar patch no lote
            });
        }

        // ── PatchExecution — execução física (P2). P1 só cria o contrato/estrutura. State machine PRÓPRIA. ──
        if (! Schema::hasTable('patch_executions')) {
            Schema::create('patch_executions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('patch_request_id');
                $t->uuid('execution_id');            // at-most-once
                $t->string('workspace_unit_id', 80)->nullable(); // unidade física travada
                $t->string('execution_mode', 12);
                $t->string('adapter', 40)->nullable();
                // pending → claimed → preparation → base_verified → patch_effect_started → patch_effect_committed
                //   → artifact_verified → candidate | failed | partial | indeterminate | contradicted | cancelled
                $t->string('status', 24)->default('pending');
                $t->string('outcome', 24)->nullable();
                $t->string('error', 120)->nullable();
                $t->string('agent_id', 80)->nullable();
                // Marcadores de journal (evidência causal p/ P2 — nunca retry).
                $t->timestamp('execution_committed_at')->nullable();
                $t->timestamp('base_verified_at')->nullable();
                $t->timestamp('patch_effect_started_at')->nullable();
                $t->timestamp('patch_effect_committed_at')->nullable();
                $t->timestamp('artifact_verified_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamp('deadline_at')->nullable();
                $t->jsonb('diagnostics')->nullable(); // SAFE only
                $t->timestamps();
                $t->unique('execution_id');
                $t->index('patch_request_id');
                $t->index('status');
            });
        }

        // ── PatchedArtifactCandidate — só de execução completa. artifact ≠ known_good ≠ published. ──
        if (! Schema::hasTable('patch_artifact_candidates')) {
            Schema::create('patch_artifact_candidates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('patch_execution_id');
                $t->unsignedBigInteger('patch_request_id');
                $t->unsignedBigInteger('environment_id');
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->string('candidate_digest', 64);  // ArtifactIdentity (base + patch = candidato), on-prem
                $t->string('base_rpo_digest', 64);   // base provada
                $t->string('batch_digest', 64);
                $t->jsonb('provenance')->nullable();  // patch input ids + base + execution + capability version
                $t->string('capability_adapter_version', 40)->nullable();
                $t->string('classification', 16)->nullable();
                $t->string('handoff_status', 20)->default('none'); // none|requested|registered (C5)
                $t->unsignedBigInteger('rpo_artifact_id')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique('patch_execution_id');
                $t->index(['customer_id', 'candidate_digest']); // NUNCA unique (sem dedup por digest)
                $t->index(['environment_id', 'handoff_status']);
            });
        }

        // ── connector_workspace_locks — lock CROSS-PRODUCER (compile|patch). UNIQUE ACTIVE por workspace_unit. ──
        //    Estrutura NOVA (não toca C6). Patch adquire em P2; Compile aderiria em C6-PHYSICAL (dependência futura,
        //    reportada — NÃO alterada aqui). P1 só cria a estrutura.
        if (! Schema::hasTable('connector_workspace_locks')) {
            Schema::create('connector_workspace_locks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('environment_id');
                $t->string('workspace_unit_id', 80); // OPACO/agent-derived
                $t->string('producer', 12);          // compile | patch
                $t->uuid('execution_ref');           // execution_id do produtor
                $t->string('status', 12)->default('active'); // active | released
                $t->timestamp('acquired_at')->nullable();
                $t->timestamp('released_at')->nullable();
                $t->timestamps();
                $t->index(['environment_id', 'workspace_unit_id']);
            });
            // 1 execução MUTÁVEL ativa por unidade física, INDEPENDENTE do producer.
            DB::statement("CREATE UNIQUE INDEX cwl_active_workspace_uq ON connector_workspace_locks (environment_id, workspace_unit_id) WHERE status = 'active'");
        }

        // ── Capability de PATCH declarada pelo agente (aditiva; espelha compile_capability; NÃO mistura). ──
        if (Schema::hasTable('connector_environment_state')
            && ! Schema::hasColumn('connector_environment_state', 'patch_capability')) {
            Schema::table('connector_environment_state', function (Blueprint $t) {
                $t->jsonb('patch_capability')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('connector_environment_state', 'patch_capability')) {
            Schema::table('connector_environment_state', fn (Blueprint $t) => $t->dropColumn('patch_capability'));
        }
        Schema::dropIfExists('connector_workspace_locks');
        Schema::dropIfExists('patch_artifact_candidates');
        Schema::dropIfExists('patch_executions');
        Schema::dropIfExists('patch_request_items');
        Schema::dropIfExists('patch_requests');
        Schema::dropIfExists('patch_inputs');
    }
};
