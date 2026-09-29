<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CodeAnalysis por fonte da GMUD: o resultado (nota/score/achados) passa a viver NO fonte
// do pacote (exibido no painel de Publicação GMUD), em vez de num comentário/interação.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gmud_package_files', function (Blueprint $table) {
            $table->string('quality_grade', 4)->nullable();     // A..F
            $table->integer('quality_score')->nullable();       // 0..100
            $table->json('quality_findings')->nullable();       // achados (cards)
            $table->timestamp('quality_analyzed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gmud_package_files', function (Blueprint $table) {
            $table->dropColumn(['quality_grade', 'quality_score', 'quality_findings', 'quality_analyzed_at']);
        });
    }
};
