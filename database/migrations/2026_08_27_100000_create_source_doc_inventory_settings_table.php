<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Fontes — Fase B (GAP-FE-002). Settings de INVENTÁRIO por escopo, INDEPENDENTE do custo de IA
 * (source_doc_ai_settings). Domínios diferentes: custo = quanto gastar/aprovação; inventário = quais arquivos
 * são elegíveis para varredura. Mesmo padrão scope_type/scope_id, mas tabela própria (sem acoplar custo).
 * Aditiva, sem backfill: tabela vazia ⇒ scanner mantém a allowlist global de config (comportamento atual).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('source_doc_inventory_settings')) {
            return;
        }
        Schema::create('source_doc_inventory_settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 16)->default('global'); // global | customer | repo
            $table->unsignedBigInteger('scope_id')->default(0);   // 0 = global; senão customer_id ou source_repo_id
            // allowlist de extensões elegíveis. NULL = herda (não há override neste nível);
            // [] = override EXPLÍCITO com nenhuma extensão elegível (distinto de NULL, nunca convertido).
            $table->jsonb('inventory_extensions')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_doc_inventory_settings');
    }
};
