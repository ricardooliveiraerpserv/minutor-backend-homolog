<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentação viva por fonte (gerada na GMUD): conteúdo atual (IA) + histórico de alterações
 * que acumula a cada GMUD. O .docx é regenerado a partir daqui a cada mudança.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('source_docs')) {
            Schema::create('source_docs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('owner');
                $table->string('repository');
                $table->string('path', 1024);
                $table->text('objetivo')->nullable();
                $table->text('estrutura')->nullable();
                $table->text('tabelas')->nullable();
                $table->timestamps();
                $table->unique(['owner', 'repository', 'path'], 'source_docs_owner_repo_path_uq');
                $table->index('customer_id');
            });
        }

        if (!Schema::hasTable('source_doc_changes')) {
            Schema::create('source_doc_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('source_doc_id')->constrained('source_docs')->cascadeOnDelete();
                $table->unsignedBigInteger('ticket_id')->nullable();
                $table->string('ticket_number')->nullable();
                $table->string('responsavel')->nullable();
                $table->text('resumo')->nullable();
                $table->timestamps();
                $table->index('source_doc_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('source_doc_changes');
        Schema::dropIfExists('source_docs');
    }
};
