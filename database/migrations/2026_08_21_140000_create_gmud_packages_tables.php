<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GMUD — Publicação Governada de Fontes (Central de Fontes). G0.
 *
 * Substitui o auto-commit silencioso (ZIP na solução → commit no Git) por um PACOTE governado:
 * o ZIP recebido é preservado imutável (via attachment), extraído com segurança, casado com o
 * acervo/Git de forma determinística e — só depois de aceite explícito (G7) — publicado.
 *
 * DESACOPLA UPLOAD de PUBLISH: estas tabelas registram RECEBIMENTO + EVIDÊNCIA. Nenhuma linha
 * aqui gera commit; a publicação é uma ação posterior, governada, ainda não implementada (G7).
 *
 * Convenção da família source_docs: sem company_id (multiempresa gated off — segue source_docs),
 * FK com cascade, SHA como string(64), JSON via ->json(). Colunas [G3+]/[G4-G7] já nascem
 * nullable para suportar as fases seguintes sem reconstrução do modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gmud_packages')) {
            Schema::create('gmud_packages', function (Blueprint $table) {
                $table->id();
                // Chamado GMUD dono do pacote (o wizard vive dentro do chamado).
                $table->foreignId('ticket_id')->constrained('helpdesk_tickets')->cascadeOnDelete();
                // Cliente denormalizado p/ resolver repo/escopo (nullable: pode não estar setado ainda).
                $table->unsignedBigInteger('customer_id')->nullable();
                // Repo de destino RESOLVIDO — exigido só no publish (G7); nulo em G0-G2.
                $table->unsignedBigInteger('source_repo_id')->nullable();
                // O ZIP ORIGINAL IMUTÁVEL (Attachment — sha256/dedup/auditoria grátis).
                $table->unsignedBigInteger('attachment_id')->nullable();
                $table->string('original_name');
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->string('sha256', 64)->nullable();     // sha256 do ZIP inteiro
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->timestamp('received_at')->nullable();
                // [G3+ nullable] classificação/projeto — reservado, sem uso em G0-G2.
                $table->string('classification', 30)->nullable();  // ex.: projeto | avulso (G3)
                $table->string('project_name')->nullable();
                $table->string('project_folder')->nullable();
                // Máquina de estados do pacote (ativos em G0-G2: received/extracting/analyzing/analyzed/failed).
                $table->string('status', 30)->default('received');
                $table->string('error', 300)->nullable();
                $table->timestamps();

                $table->index('ticket_id');
                $table->index('customer_id');
                $table->index('status');
                $table->index('sha256');
            });
        }

        if (! Schema::hasTable('gmud_package_files')) {
            Schema::create('gmud_package_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gmud_package_id')->constrained('gmud_packages')->cascadeOnDelete();
                // EVIDÊNCIA do path dentro do ZIP — NUNCA autoridade do destino Git.
                $table->string('path_in_zip', 1024);
                $table->string('filename');
                $table->string('extension', 32)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->string('sha256', 64)->nullable();         // sha256 do CONTEÚDO do arquivo
                // git blob sha = sha1("blob <len>\0<bytes>") — p/ casar com GithubAppAuth::treeBlobShas.
                $table->string('git_blob_sha', 64)->nullable();
                // Git não preserva mtime → guardamos como metadado (do statIndex do ZIP).
                $table->timestamp('mtime')->nullable();
                $table->boolean('is_source')->default(false);     // extensão reconhecida como fonte
                // Matching determinístico (G2): existing|new|ambiguous|identical (sem IA).
                $table->string('match_status', 20)->nullable();
                $table->unsignedBigInteger('matched_source_doc_id')->nullable();  // "Abrir no Acervo"
                $table->string('matched_git_path', 1024)->nullable();
                $table->json('match_candidates')->nullable();     // ambíguo: [{path,blob_sha,source_doc_id}]
                $table->json('match_evidence')->nullable();       // como casou / resumo do diff
                // [G4-G7 nullable, reservado] destino/ação/publicação — sem uso em G0-G2.
                $table->string('action', 20)->nullable();         // add|modify|move|skip (G4+)
                $table->string('dest_git_path', 1024)->nullable();
                $table->string('old_git_path', 1024)->nullable();
                $table->string('published_blob_sha', 64)->nullable();
                $table->timestamps();

                $table->index('gmud_package_id');
                $table->index('match_status');
                $table->index('filename');
                $table->index('matched_source_doc_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gmud_package_files');
        Schema::dropIfExists('gmud_packages');
    }
};
