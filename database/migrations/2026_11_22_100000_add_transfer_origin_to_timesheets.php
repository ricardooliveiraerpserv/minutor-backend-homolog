<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastreio de ORIGEM da Transferência de Apontamentos. Sem estas colunas a empresa de origem
 * se perdia ao migrar (customer_id é sobrescrito pelo do projeto destino), impossibilitando o
 * total por empresa. Aditivo e nullable (safe em tabela viva); preenchido em TODA migração
 * (auto no save + retroativa) e por backfill onde não-ambíguo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (! Schema::hasColumn('timesheets', 'transfer_rule_id')) {
                $table->unsignedBigInteger('transfer_rule_id')->nullable()->after('real_project_id');
            }
            if (! Schema::hasColumn('timesheets', 'transfer_source_customer_id')) {
                $table->unsignedBigInteger('transfer_source_customer_id')->nullable()->after('transfer_rule_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            foreach (['transfer_rule_id', 'transfer_source_customer_id'] as $col) {
                if (Schema::hasColumn('timesheets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
