<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 0 — Documentação de fonte v2. Reestrutura source_docs como "documento vivo":
 * identidade completa (cliente + repo + branch + path), ponteiro para a versão vigente,
 * SHA do código vigente, JSON consolidado com schema versionado e estado de análise.
 * As 3 colunas texto do modelo antigo (objetivo/estrutura/tabelas) saem — serão substituídas
 * pelo documentation_json (preenchido pelo novo motor nas fases seguintes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('source_docs')) {
            return;
        }
        Schema::table('source_docs', function (Blueprint $table) {
            if (!Schema::hasColumn('source_docs', 'source_repo_id')) {
                $table->unsignedBigInteger('source_repo_id')->nullable()->after('customer_id');
            }
            if (!Schema::hasColumn('source_docs', 'branch')) {
                $table->string('branch')->default('main')->after('repository');
            }
            if (!Schema::hasColumn('source_docs', 'filename')) {
                $table->string('filename')->nullable()->after('path');
            }
            if (!Schema::hasColumn('source_docs', 'tipo')) {
                $table->string('tipo', 40)->nullable()->after('filename');
            }
            if (!Schema::hasColumn('source_docs', 'lang')) {
                $table->string('lang', 20)->nullable()->after('tipo');
            }
            if (!Schema::hasColumn('source_docs', 'size_bytes')) {
                $table->unsignedBigInteger('size_bytes')->nullable()->after('lang');
            }
            if (!Schema::hasColumn('source_docs', 'current_source_sha')) {
                $table->string('current_source_sha', 64)->nullable()->after('size_bytes');
            }
            if (!Schema::hasColumn('source_docs', 'current_version_id')) {
                $table->unsignedBigInteger('current_version_id')->nullable()->after('current_source_sha');
            }
            if (!Schema::hasColumn('source_docs', 'documentation_json')) {
                $table->json('documentation_json')->nullable()->after('current_version_id');
            }
            if (!Schema::hasColumn('source_docs', 'schema_version')) {
                $table->unsignedSmallInteger('schema_version')->default(1)->after('documentation_json');
            }
            if (!Schema::hasColumn('source_docs', 'analysis_status')) {
                $table->string('analysis_status', 20)->default('pending')->after('schema_version');
            }
            if (!Schema::hasColumn('source_docs', 'analysis_error')) {
                $table->text('analysis_error')->nullable()->after('analysis_status');
            }
            if (!Schema::hasColumn('source_docs', 'attempts')) {
                $table->unsignedInteger('attempts')->default(0)->after('analysis_error');
            }
        });

        // Identidade: unicidade por owner+repository+branch+path (git-única), NUNCA só filename.
        // Substitui a unique antiga (owner,repository,path), que não considerava a branch.
        Schema::table('source_docs', function (Blueprint $table) {
            try {
                $table->dropUnique('source_docs_owner_repo_path_uq');
            } catch (\Throwable $e) {
                // já removida
            }
        });
        // Backfill de branch/filename dos registros existentes antes de criar a unique nova.
        \Illuminate\Support\Facades\DB::table('source_docs')->whereNull('branch')->update(['branch' => 'main']);
        \Illuminate\Support\Facades\DB::statement("UPDATE source_docs SET filename = regexp_replace(path, '^.*/', '') WHERE filename IS NULL");
        Schema::table('source_docs', function (Blueprint $table) {
            $table->unique(['owner', 'repository', 'branch', 'path'], 'source_docs_identity_uq');
            $table->index('source_repo_id');
            $table->index('current_version_id');
            $table->index('analysis_status');
        });

        // Remove as 3 colunas do modelo antigo (substituídas por documentation_json).
        Schema::table('source_docs', function (Blueprint $table) {
            foreach (['objetivo', 'estrutura', 'tabelas'] as $col) {
                if (Schema::hasColumn('source_docs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('source_docs')) {
            return;
        }
        Schema::table('source_docs', function (Blueprint $table) {
            foreach (['objetivo', 'estrutura', 'tabelas'] as $col) {
                if (!Schema::hasColumn('source_docs', $col)) {
                    $table->text($col)->nullable();
                }
            }
            try { $table->dropUnique('source_docs_identity_uq'); } catch (\Throwable $e) {}
            foreach (['source_repo_id', 'branch', 'filename', 'tipo', 'lang', 'size_bytes', 'current_source_sha', 'current_version_id', 'documentation_json', 'schema_version', 'analysis_status', 'analysis_error', 'attempts'] as $col) {
                if (Schema::hasColumn('source_docs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('source_docs', function (Blueprint $table) {
            $table->unique(['owner', 'repository', 'path'], 'source_docs_owner_repo_path_uq');
        });
    }
};
