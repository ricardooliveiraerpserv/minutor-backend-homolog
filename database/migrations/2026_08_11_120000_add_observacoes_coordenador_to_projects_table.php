<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('projects', 'observacoes_coordenador')) {
            Schema::table('projects', function (Blueprint $table) {
                // Observações destinadas ao coordenador — visíveis a todos os perfis.
                // NÃO deve conter informações sensíveis (alerta no formulário).
                $table->longText('observacoes_coordenador')->nullable()->after('observacoes_contrato');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('projects', 'observacoes_coordenador')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('observacoes_coordenador');
            });
        }
    }
};
