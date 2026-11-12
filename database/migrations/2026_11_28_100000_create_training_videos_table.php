<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Aprenda o Minutor" — biblioteca de vídeos de treinamento por perfil.
 * audiences = chaves de perfil do Configurador (admin, administrativo, coordenador_projetos,
 * coordenador_sustentacao, consultor_horista, consultor_banco_de_horas, consultor_fixo,
 * parceiro_gestor, parceiro_simples, cliente). Vazio/null = todos. Aditiva/idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('training_videos')) return;
        Schema::create('training_videos', function (Blueprint $t) {
            $t->id();
            $t->string('title', 160);
            $t->text('description')->nullable();
            $t->string('category', 80)->nullable();
            $t->json('audiences')->nullable();
            $t->string('file_path', 255)->nullable();
            $t->string('original_name', 255)->nullable();
            $t->string('mime', 80)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->integer('sort_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamp('content_updated_at')->nullable();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_videos');
    }
};
