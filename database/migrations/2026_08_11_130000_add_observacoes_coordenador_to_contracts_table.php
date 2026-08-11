<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('contracts', 'observacoes_coordenador')) {
            Schema::table('contracts', function (Blueprint $table) {
                // Observações destinadas ao coordenador — copiadas ao projeto na geração.
                // NÃO deve conter informações sensíveis (alerta no formulário).
                $table->longText('observacoes_coordenador')->nullable()->after('observacoes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contracts', 'observacoes_coordenador')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->dropColumn('observacoes_coordenador');
            });
        }
    }
};
