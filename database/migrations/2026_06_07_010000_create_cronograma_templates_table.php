<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modelos de cronograma reutilizáveis (ex.: "Atualização de Versão").
 * `payload` guarda a árvore relativa (etapas → sub-etapas → atividades) com
 * offsets de dias a partir de uma âncora, pra reencaixar as datas ao aplicar.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cronograma_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->jsonb('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cronograma_templates');
    }
};
