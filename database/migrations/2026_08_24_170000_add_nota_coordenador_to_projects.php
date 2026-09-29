<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Observação editável PELO coordenador (distinta de observacoes_coordenador,
    // que são instruções PARA o coordenador).
    public function up(): void
    {
        if (!Schema::hasColumn('projects', 'nota_coordenador')) {
            Schema::table('projects', function (Blueprint $t) {
                $t->text('nota_coordenador')->nullable()->after('observacoes_coordenador');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('projects', 'nota_coordenador')) {
            Schema::table('projects', function (Blueprint $t) {
                $t->dropColumn('nota_coordenador');
            });
        }
    }
};
