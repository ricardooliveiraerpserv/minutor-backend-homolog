<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Fixar" (pin) — máx 2 por escopo — em Comentários/Diário (contract_request_messages),
 * Comentários do contrato (contract_messages) e Documentos (attachments).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['contract_request_messages', 'contract_messages', 'attachments'] as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'pinned_at')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->timestamp('pinned_at')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['contract_request_messages', 'contract_messages', 'attachments'] as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'pinned_at')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->dropColumn('pinned_at');
                });
            }
        }
    }
};
