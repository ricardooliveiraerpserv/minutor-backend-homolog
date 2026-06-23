<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — próxima ação (texto) na oportunidade (Item 1) e motivo de perda estruturado
 * (Item 2). Nullable no banco; obrigatoriedade aplicada por regra (criação / perda).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_opportunities', 'proxima_acao')) {
                $table->string('proxima_acao', 200)->nullable()->after('proxima_acao_at');
            }
            if (!Schema::hasColumn('crm_opportunities', 'loss_reason_id')) {
                $table->foreignId('loss_reason_id')->nullable()->after('motivo')->constrained('crm_loss_reasons')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table) {
            if (Schema::hasColumn('crm_opportunities', 'proxima_acao')) $table->dropColumn('proxima_acao');
            if (Schema::hasColumn('crm_opportunities', 'loss_reason_id')) $table->dropConstrainedForeignId('loss_reason_id');
        });
    }
};
