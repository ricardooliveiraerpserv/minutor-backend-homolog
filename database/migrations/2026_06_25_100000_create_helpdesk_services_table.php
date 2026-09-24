<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de Serviços do Help Desk — árvore de serviços que classifica os chamados
 * (estilo Movidesk). Cada serviço define disponibilidade (público/interno), visibilidade
 * e quem pode selecioná-lo (agente/cliente), código, se permite conclusão e se está ativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable(); // hierarquia (subserviço)
            $table->string('name', 160);
            $table->string('code', 40)->nullable();
            $table->string('availability', 24)->default('public_and_internal'); // public_and_internal | public | internal
            $table->boolean('visible_to_agent')->default(true);
            $table->boolean('visible_to_client')->default(true);
            $table->boolean('selectable_by_agent')->default(true);
            $table->boolean('selectable_by_client')->default(false);
            $table->boolean('allow_conclusion')->default(true);
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index('parent_id');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_services');
    }
};
