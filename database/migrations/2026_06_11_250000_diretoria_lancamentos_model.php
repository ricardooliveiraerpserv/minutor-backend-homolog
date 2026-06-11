<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Header (por diretor/mês): rótulo da cooperativa + taxa/INSS.
        Schema::table('fechamento_diretoria', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_diretoria', 'cooperativa')) {
                $table->string('cooperativa')->nullable()->after('year_month');
            }
            if (!Schema::hasColumn('fechamento_diretoria', 'taxa_inss')) {
                $table->decimal('taxa_inss', 14, 2)->nullable()->after('cooperativa');
            }
        });

        // Itens = lançamentos (soma/desconto) que compõem o total.
        Schema::table('fechamento_diretoria_itens', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_diretoria_itens', 'descricao')) {
                $table->string('descricao')->nullable()->after('ordem');
            }
            if (!Schema::hasColumn('fechamento_diretoria_itens', 'tipo')) {
                $table->string('tipo', 10)->default('soma')->after('descricao'); // soma | desconto
            }
            if (!Schema::hasColumn('fechamento_diretoria_itens', 'valor')) {
                $table->decimal('valor', 14, 2)->nullable()->after('tipo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_diretoria', function (Blueprint $table) {
            foreach (['cooperativa', 'taxa_inss'] as $c) {
                if (Schema::hasColumn('fechamento_diretoria', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('fechamento_diretoria_itens', function (Blueprint $table) {
            foreach (['descricao', 'tipo', 'valor'] as $c) {
                if (Schema::hasColumn('fechamento_diretoria_itens', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
