<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Connector-5.1 — FUNDAÇÃO da publicação de RPO (SÓ saber/registrar/qualificar/agrupar/prever; ZERO
 * publicação). Nenhum promote/rollback/claim/execução/adapter/bytes aqui.
 *  - rpo_artifacts: registro GOVERNADO de artefatos (discovered=hash observado no C-2, NÃO persistido/confiável;
 *    registered=persistido c/ proveniência, IMUTÁVEL; correção = nova REVISÃO, nunca edição).
 *  - rpo_targets (+ appservers): alvo LÓGICO de RPO (cadastral + confirmação por observação). 1 appserver_ref
 *    em no máx. 1 target ativo por ambiente. mesmo SHA ≠ mesmo RPO físico → nunca descoberta vinculante.
 *  - rpo_qualifications: known_good CONTEXTUAL (artifact × target). Histórico ordenado; last_known_good deriva
 *    da qualificação válida mais recente. Publicação bem-sucedida NÃO qualifica sozinha.
 * + coluna rpo_capability em connector_environment_state (capability declarativa/versionada pelo agente).
 * Sem company_id (segue source_docs). Nenhum byte/path/credencial.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rpo_artifacts')) {
            Schema::create('rpo_artifacts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->string('hash', 64);                 // sha256 (identidade)
                $t->string('version', 60)->nullable();  // rótulo TTTP/RPO
                $t->string('provenance', 300)->nullable();
                $t->jsonb('compatibility')->nullable(); // versão/build/patch de AppServer suportados
                $t->string('source_identity', 200)->nullable(); // ponteiro OPACO on-prem (NUNCA bytes/path)
                $t->string('status', 12)->default('registered'); // só 'registered' é persistido (discovered é observado)
                $t->unsignedSmallInteger('revision')->default(1);
                $t->unsignedBigInteger('supersedes_id')->nullable();     // esta revisão corrige...
                $t->unsignedBigInteger('superseded_by_id')->nullable();  // ...substituída por
                $t->unsignedBigInteger('registered_by')->nullable();
                $t->timestamp('registered_at')->nullable();
                $t->timestamps();
                $t->index(['customer_id', 'hash']);
                $t->index(['customer_id', 'status']);
            });
        }

        if (! Schema::hasTable('rpo_targets')) {
            Schema::create('rpo_targets', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('environment_id');
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->string('name', 120);
                $t->string('status', 20)->default('pending_confirmation'); // pending_confirmation|confirmed
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('confirmed_by')->nullable();
                $t->timestamp('confirmed_at')->nullable();
                $t->timestamps();
                $t->index(['environment_id', 'status']);
            });
        }

        if (! Schema::hasTable('rpo_target_appservers')) {
            Schema::create('rpo_target_appservers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('rpo_target_id');
                $t->unsignedBigInteger('environment_id');
                $t->uuid('appserver_ref');
                $t->timestamp('created_at')->nullable();
                // 1 appserver_ref em no máximo 1 target por ambiente (evita ambiguidade de lock/autoridade).
                $t->unique(['environment_id', 'appserver_ref']);
                $t->index('rpo_target_id');
            });
        }

        if (! Schema::hasTable('rpo_qualifications')) {
            Schema::create('rpo_qualifications', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('rpo_artifact_id');   // deve ser registered
                $t->unsignedBigInteger('rpo_target_id');     // contexto (artifact × target)
                $t->unsignedBigInteger('environment_id');
                $t->string('hash', 64);                      // denormalizado
                $t->unsignedBigInteger('qualified_by');
                $t->string('reason', 300);
                $t->timestamp('qualified_at');
                $t->timestamp('revoked_at')->nullable();     // known_good = revoked_at IS NULL
                $t->timestamps();
                $t->index(['rpo_target_id', 'revoked_at']);
            });
        }

        // Capability de publicação declarada pelo agente (persistida/exibida; NÃO invocável no C5.1).
        if (Schema::hasTable('connector_environment_state')
            && ! Schema::hasColumn('connector_environment_state', 'rpo_capability')) {
            Schema::table('connector_environment_state', function (Blueprint $t) {
                $t->jsonb('rpo_capability')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rpo_qualifications');
        Schema::dropIfExists('rpo_target_appservers');
        Schema::dropIfExists('rpo_targets');
        Schema::dropIfExists('rpo_artifacts');
        if (Schema::hasColumn('connector_environment_state', 'rpo_capability')) {
            Schema::table('connector_environment_state', function (Blueprint $t) {
                $t->dropColumn('rpo_capability');
            });
        }
    }
};
