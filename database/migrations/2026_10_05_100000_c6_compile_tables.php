<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connector-6 (C6) — COMPILE. Fundação do produto de compilação governada. Compile PRODUZ um ARTEFATO
 * candidato; Compile NÃO publica RPO (a autoridade destrutiva de publicação continua exclusiva do C5).
 * Nenhum caminho compile→publish automático. Sem company_id (segue source_docs / rpo_*).
 *
 * Invariantes congelados (C6.0/C6.1):
 *  - CompileInputIdentity (fonte: blob_sha) ≠ CompileExecutionIdentity (execution_id) ≠ ArtifactIdentity (digest).
 *  - NÃO assumir determinismo do compilador. NÃO deduplicar por artifact_digest (índice comum, NUNCA unique).
 *  - CompileContext = fatores OBSERVADOS que influenciam a saída (sem fórmula/hash definidos ainda).
 *  - ArtifactCandidate só existe quando a execução termina succeeded. artifact ≠ known_good, artifact ≠ published.
 *  - Zero bytes/paths/secrets/log bruto persistidos — só identidade + metadata SANITIZADA (SAFE).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── CompileRequest — a INTENÇÃO de compilar uma fonte identificada, num ambiente, num modo. ──
        if (! Schema::hasTable('compile_requests')) {
            Schema::create('compile_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('customer_id')->nullable(); // revalidado server-side, NUNCA autoridade
                $t->unsignedBigInteger('environment_id');
                // Identidade da FONTE (git) — autoridade por BLOB, não por commit HEAD.
                $t->string('repository', 200);        // owner/repo
                $t->string('branch', 200);
                $t->string('source_path', 500);       // caminho lógico da fonte
                $t->string('source_commit_sha', 64)->nullable();
                $t->string('source_blob_sha', 64);    // CompileInputIdentity
                $t->string('language', 20);           // advpl | tlpp
                $t->string('target', 80)->nullable(); // rótulo de target/runtime do compilador
                $t->string('execution_mode', 12);     // fixture | simulated | live (SEM fallback silencioso)
                $t->string('classification', 16)->nullable(); // test|demo|operational (NÃO relaxa segurança)
                $t->string('status', 20)->default('open'); // open | executing | completed | failed | canceled
                $t->uuid('correlation_id');           // correlação C1
                $t->unsignedBigInteger('requested_by')->nullable();
                $t->timestamp('requested_at')->nullable();
                $t->timestamps();
                $t->index(['customer_id', 'environment_id']);
                $t->index(['environment_id', 'status']);
                $t->index('correlation_id');
            });
        }

        // ── CompileContext — fatores OBSERVADOS capazes de influenciar a saída. SEM fórmula/hash (C6.1 P5). ──
        if (! Schema::hasTable('compile_contexts')) {
            Schema::create('compile_contexts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('compile_request_id');
                $t->string('compiler_identity', 120)->nullable(); // opaco (sem secret/host/path)
                $t->string('compiler_version', 60)->nullable();
                $t->string('compiler_build', 60)->nullable();
                $t->string('compiler_patch', 60)->nullable();
                $t->string('target_runtime', 80)->nullable();
                $t->jsonb('factors')->nullable();     // flags/includes/dependencies/outros — SANITIZADO
                $t->string('note', 300)->nullable();
                $t->timestamp('captured_at')->nullable();
                $t->timestamps();
                $t->index('compile_request_id');
            });
        }

        // ── CompileExecution — a EXECUÇÃO (at-most-once por execution_id). State machine própria (não C5). ──
        if (! Schema::hasTable('compile_executions')) {
            Schema::create('compile_executions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('compile_request_id');
                $t->uuid('execution_id');             // CompileExecutionIdentity — imutável, at-most-once
                $t->string('execution_mode', 12);     // fixture | simulated | live
                $t->string('adapter', 40)->nullable(); // fixture_compile | simulated_compile | live_compile
                // pending → claimed → running → succeeded | failed | timed_out | cancelled | unknown
                $t->string('status', 20)->default('pending');
                $t->string('outcome', 20)->nullable(); // espelha o terminal (clareza/consulta)
                $t->string('error', 120)->nullable();  // motivo SANITIZADO (ex.: live_unavailable, timeout)
                $t->string('agent_id', 80)->nullable(); // só live (agente conector)
                $t->timestamp('claimed_at')->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamp('deadline_at')->nullable();
                $t->jsonb('diagnostics')->nullable();  // SAFE only (contagens/mensagens classificadas) — sem log bruto
                $t->timestamps();
                $t->unique('execution_id');
                $t->index('compile_request_id');
                $t->index('status');
            });
        }

        // ── ArtifactCandidate — só existe quando execution.outcome = succeeded. artifact ≠ known_good/published. ──
        if (! Schema::hasTable('artifact_candidates')) {
            Schema::create('artifact_candidates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('compile_execution_id');
                $t->unsignedBigInteger('compile_request_id');
                $t->unsignedBigInteger('environment_id');
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->string('artifact_digest', 64);    // ArtifactIdentity (sha256 calculado on-prem/adapter)
                // Fronteira do artefato (C6.1 Ajuste A): standalone | rpo_apo_full | rpo_apo_incremental | unknown
                $t->string('artifact_unit', 40)->default('unknown');
                $t->unsignedBigInteger('size_bytes')->nullable(); // se seguro
                $t->jsonb('artifact_metadata')->nullable();       // SANITIZADO (sem bytes/path/secret)
                $t->jsonb('provenance')->nullable();  // source blob/commit + execution/context ids
                $t->string('classification', 16)->nullable();
                // Handoff GOVERNADO ao C5 (register). C6 NUNCA promove. none | requested | registered
                $t->string('handoff_status', 20)->default('none');
                $t->unsignedBigInteger('rpo_artifact_id')->nullable(); // preenchido quando o C5 registra
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique('compile_execution_id');   // 1 candidato por execução bem-sucedida
                // Índice por digest é COMUM (NUNCA unique) — determinismo não assumido, sem dedup por digest.
                $t->index(['customer_id', 'artifact_digest']);
                $t->index(['environment_id', 'handoff_status']);
            });
        }

        // Capability de COMPILAÇÃO declarada pelo agente (aditiva; espelha rpo_capability, NÃO mistura com ela).
        if (Schema::hasTable('connector_environment_state')
            && ! Schema::hasColumn('connector_environment_state', 'compile_capability')) {
            Schema::table('connector_environment_state', function (Blueprint $t) {
                $t->jsonb('compile_capability')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('connector_environment_state', 'compile_capability')) {
            Schema::table('connector_environment_state', fn (Blueprint $t) => $t->dropColumn('compile_capability'));
        }
        Schema::dropIfExists('artifact_candidates');
        Schema::dropIfExists('compile_executions');
        Schema::dropIfExists('compile_contexts');
        Schema::dropIfExists('compile_requests');
    }
};
