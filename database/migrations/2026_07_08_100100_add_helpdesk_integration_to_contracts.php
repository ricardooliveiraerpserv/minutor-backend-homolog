<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chave de integração Help Desk no contrato de sustentação. Quando LIGADA, as
 * interações dos chamados vinculados a este contrato movimentam horas (geram
 * apontamento oficial) exatamente como o Movidesk fazia — porém dentro do Minutor.
 * Default OFF: nenhum contrato existente passa a movimentar sem opt-in explícito.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('contracts', 'helpdesk_integration_enabled')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->boolean('helpdesk_integration_enabled')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contracts', 'helpdesk_integration_enabled')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->dropColumn('helpdesk_integration_enabled');
            });
        }
    }
};
