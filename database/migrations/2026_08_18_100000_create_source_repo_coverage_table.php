<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Fontes — C3.5. Snapshot de COBERTURA por repositório cadastrado (client_source_repos):
 * serve ao dashboard E como CHECKPOINT operacional do inventário (retomável/auditável).
 *
 * "Não catalogado" = arquivo existe no Git de um repo CADASTRADO mas ainda não há source_docs.
 * (A C3.5 só varre repos cadastrados; descoberta de repos da org sem cliente é auditoria futura,
 *  fora daqui — por isso NÃO existe "git sem cadastro".)
 *
 * Estados distintos (não misturar): coberto · documentação desatualizada (blob difere) ·
 * índice pendente/stale (source_doc_index divergente — problema de índice, não de doc).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('source_repo_coverage')) {
            return;
        }
        Schema::create('source_repo_coverage', function (Blueprint $table) {
            $table->unsignedBigInteger('source_repo_id')->primary();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('owner');
            $table->string('repository');
            $table->string('branch');

            // estado operacional (checkpoint / retomada / auditoria)
            $table->string('scan_status', 20)->default('pending'); // pending|running|completed|partial|failed|rate_limited
            $table->timestamp('scan_started_at')->nullable();
            $table->timestamp('scan_finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('last_scan_cursor', 1024)->nullable();   // último path processado (retoma varredura)

            // contagem da varredura
            $table->unsignedInteger('github_files')->default(0);          // arquivos na árvore do repo
            $table->unsignedInteger('eligible_source_files')->default(0); // após extensão + base_path
            $table->unsignedInteger('new_files')->default(0);             // NÃO catalogados (viram catalogados)
            $table->unsignedInteger('unchanged_files')->default(0);       // cobertos (blob igual)
            $table->unsignedInteger('changed_files')->default(0);         // documentação desatualizada (blob difere)
            $table->unsignedInteger('ignored_files')->default(0);         // fora de extensão/base_path

            // cobertura consolidada (do banco)
            $table->unsignedInteger('cataloged')->default(0);     // source_docs deste repo
            $table->unsignedInteger('deterministic')->default(0); // com camada determinística
            $table->unsignedInteger('semantic')->default(0);      // com semântica (Camada 2)
            $table->unsignedInteger('indexed')->default(0);       // no índice C2 e não-stale
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();
            $table->foreign('source_repo_id')->references('id')->on('client_source_repos')->cascadeOnDelete();
            $table->index('customer_id');
            $table->index('scan_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_repo_coverage');
    }
};
