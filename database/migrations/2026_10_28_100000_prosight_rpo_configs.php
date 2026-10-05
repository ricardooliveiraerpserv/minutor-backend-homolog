<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Config REST AdvPL (RPO) POR AMBIENTE — paridade com o configurador do ProSight enviado
 * (prosight-service): rpoApiUrl / rpoApiUser / rpoApiPassword / rpoExclusionPatterns.
 * O Minutor (servidor) consulta o RPO direto com essas credenciais. Senha cifrada (app key),
 * nunca retornada ao FE (só flag *_set). Git (url/branch/token) já vive em client_source_repos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('prosight_rpo_configs')) {
            return;
        }
        Schema::create('prosight_rpo_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained('env_environments')->cascadeOnDelete()->unique();
            $table->string('rpo_api_url', 500)->nullable();
            $table->string('rpo_api_user', 120)->nullable();
            $table->text('rpo_api_password')->nullable();        // cifrada (Crypt) — server-side usável, nunca exposta
            $table->string('rpo_exclusion_patterns', 1000)->nullable(); // ex.: "._*,TEST*,_BINA*,*TST*"
            $table->boolean('allow_insecure_tls')->default(false); // on-prem cert self-signed
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prosight_rpo_configs');
    }
};
