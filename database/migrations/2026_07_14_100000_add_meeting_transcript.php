<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 4 — guarda o TEXTO da transcrição (transcript_url é só o ponteiro do provider).
// Serve p/ reprocessar o resumo/ata por IA e, quando o Teams ligar, receber o conteúdo puxado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            if (!Schema::hasColumn('meetings', 'transcript')) {
                $table->text('transcript')->nullable()->after('transcript_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            if (Schema::hasColumn('meetings', 'transcript')) {
                $table->dropColumn('transcript');
            }
        });
    }
};
