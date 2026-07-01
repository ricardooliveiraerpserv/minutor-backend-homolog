<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observabilidade da Plataforma de Documentos (contexto oficial, item 4):
 * registrar tempo de render. Tamanho do PDF já vive em attachments.size_bytes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (!Schema::hasColumn('documents', 'render_ms')) {
                $table->unsignedInteger('render_ms')->nullable()->after('renderer');
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'render_ms')) {
                $table->dropColumn('render_ms');
            }
        });
    }
};
