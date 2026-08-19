<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Central de Fontes — ajustes por REPOSITÓRIO (nível Central). hidden = repo desabilitado,
// some das consultas (Acervo/Impacto/Busca/Catálogo/Cobertura) mas NÃO para a ingestão.
// Chave = (customer_id, repository).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_doc_repo_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id');
            $t->string('repository');
            $t->boolean('hidden')->default(false);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['customer_id', 'repository']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_doc_repo_settings');
    }
};
