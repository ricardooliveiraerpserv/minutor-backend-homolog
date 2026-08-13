<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 0 da "Solicitação de código-fonte" (Help Desk): fontes Git AUTORIZADAS por cliente.
 * Um cliente pode ter N repositórios/branches (não 1:1). O backend resolve cliente → repos
 * ativos → branch → base_path; o frontend nunca dirige owner/repo/branch. READ-ONLY no GitHub.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('client_source_repos')) {
            return;
        }
        Schema::create('client_source_repos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('owner', 120);                       // org/owner (erpserv-clientes, Auster-Nutricao-Animal-LTDA)
            $table->string('repository', 140);                  // repo (promax, Protheus)
            $table->string('branch', 140);                      // EXPLÍCITA — nunca assume main
            $table->string('base_path', 400)->default('');      // subpasta opcional dentro do repo
            $table->string('tipo', 20)->default('protheus');    // protheus|fluig|integracoes|outros
            $table->string('descricao', 200)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['customer_id', 'active']);
        });

        // Único por (cliente, owner, repo, branch, base_path) — só entre linhas vivas
        // (parcial em deleted_at), para permitir re-cadastro após exclusão de config nunca usada.
        DB::statement(
            'CREATE UNIQUE INDEX client_source_repos_unique
             ON client_source_repos (customer_id, owner, repository, branch, base_path)
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('client_source_repos');
    }
};
