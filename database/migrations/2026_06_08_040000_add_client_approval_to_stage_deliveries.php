<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow de aprovação do cliente na atividade.
 *
 * Quando o card entra em "Aguardando cliente" (waiting_client), abre-se uma
 * aprovação pendente. O cliente envolvido (Portal) OU o coordenador/admin
 * (interno) aprova/reprova. Estado atual fica na própria delivery; o histórico
 * vai pra timeline (StageActivityEvent).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('stage_deliveries', function (Blueprint $table) {
            // none | pending | approved | rejected | changes_requested
            $table->string('approval_status', 24)->default('none');
            $table->timestamp('approval_requested_at')->nullable();
            $table->foreignId('approval_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approval_decided_at')->nullable();
            $table->foreignId('approval_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('approval_note')->nullable();

            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('stage_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_requested_by');
            $table->dropConstrainedForeignId('approval_decided_by');
            $table->dropColumn([
                'approval_status',
                'approval_requested_at',
                'approval_decided_at',
                'approval_note',
            ]);
        });
    }
};
