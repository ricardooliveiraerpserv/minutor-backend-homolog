<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechamento_diretoria_itens', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_diretoria_itens', 'observacao_itens')) {
                // [{ descricao: string, valor: number|null }, ...] — compõem o texto da observação.
                $table->json('observacao_itens')->nullable()->after('observacao');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_diretoria_itens', function (Blueprint $table) {
            if (Schema::hasColumn('fechamento_diretoria_itens', 'observacao_itens')) {
                $table->dropColumn('observacao_itens');
            }
        });
    }
};
