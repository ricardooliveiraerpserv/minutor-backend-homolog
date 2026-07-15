<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rotina passa a distinguir ADIANTAMENTO (desconta no fechamento) de EMPRÉSTIMO
 * (soma no mês em que foi feito e é quitado pelas parcelas nos meses seguintes).
 *  - `tipo`           : 'adiantamento' | 'emprestimo'
 *  - `data_realizado` : dia/mês em que o valor foi efetivamente entregue. Para o
 *                       empréstimo, é o mês em que o valor_total SOMA no fechamento.
 *  - `disponibilizado`: só empréstimo — se o valor JÁ foi entregue. Enquanto false,
 *                       o empréstimo fica inerte no fechamento (sem aporte e sem
 *                       quitar as parcelas). Adiantamento é sempre disponibilizado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('adiantamentos')) {
            return;
        }

        Schema::table('adiantamentos', function (Blueprint $table) {
            if (!Schema::hasColumn('adiantamentos', 'tipo')) {
                $table->string('tipo', 12)->default('adiantamento')->after('beneficiario_id');
            }
            if (!Schema::hasColumn('adiantamentos', 'data_realizado')) {
                $table->date('data_realizado')->nullable()->after('valor_total');
            }
            if (!Schema::hasColumn('adiantamentos', 'disponibilizado')) {
                $table->boolean('disponibilizado')->default(true)->after('data_realizado');
            }
        });

        // Backfill: registros legados são adiantamentos; data_realizado = 1º dia da
        // primeira competência de desconto (melhor aproximação do "quando foi feito").
        DB::table('adiantamentos')
            ->whereNull('data_realizado')
            ->update([
                'data_realizado' => DB::raw("to_date(primeira_competencia || '-01', 'YYYY-MM-DD')"),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('adiantamentos')) {
            return;
        }
        Schema::table('adiantamentos', function (Blueprint $table) {
            foreach (['tipo', 'data_realizado', 'disponibilizado'] as $col) {
                if (Schema::hasColumn('adiantamentos', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
