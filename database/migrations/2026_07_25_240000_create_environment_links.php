<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cofre de Ambientes (F1d) — Links do ambiente (portais Fluig/TSS/Portal TOTVS/
 * Power BI/RDP/SharePoint). Tudo metadado em CLARO (não são segredos).
 * Documentação usa a camada de attachments (entity_type ENV_DOC).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('env_links')) {
            Schema::create('env_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('environment_id')->constrained('env_environments')->cascadeOnDelete();
                $table->string('label');                     // [CLARO]
                $table->string('url', 1000);
                $table->string('kind', 20)->default('portal'); // fluig|tss|portal|powerbi|rdp|sharepoint|azure|aws|other
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index('environment_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('env_links');
    }
};
