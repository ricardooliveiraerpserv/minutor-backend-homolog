<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4.4 — rastreio da CAPTURA dos artefatos assinados (PDF/certificado/evidências).
 * O status_assinatura só vira "assinado" quando capture_status='concluido' (consistência:
 * nunca "assinado" sem signed_attachment_id).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('clicksign_envelopes', function (Blueprint $table) {
            if (!Schema::hasColumn('clicksign_envelopes', 'capture_status')) {
                $table->string('capture_status', 16)->nullable()->after('finished_at'); // pendente|capturando|concluido|falha
            }
            if (!Schema::hasColumn('clicksign_envelopes', 'captured_at')) {
                $table->timestamp('captured_at')->nullable()->after('capture_status');
            }
            if (!Schema::hasColumn('clicksign_envelopes', 'capture_error')) {
                $table->text('capture_error')->nullable()->after('captured_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clicksign_envelopes', function (Blueprint $table) {
            foreach (['capture_status', 'captured_at', 'capture_error'] as $col) {
                if (Schema::hasColumn('clicksign_envelopes', $col)) $table->dropColumn($col);
            }
        });
    }
};
