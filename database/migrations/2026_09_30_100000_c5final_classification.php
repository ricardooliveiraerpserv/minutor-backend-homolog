<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C5-FINAL — classificação test|demo|operational (metadata) dos registros de RPO/operações. Aditiva, nullable
 * (null = operational). É SÓ classificação/filtro/auditoria/limpeza controlada — NUNCA relaxa segurança:
 * uma operação classification=test que possa produzir efeito real continua sujeita a permissions → approvals →
 * locks → journal → at-most-once → reconciliation, exatamente como uma operational. Nenhum código gateia por
 * classification.
 */
return new class extends Migration
{
    private array $tables = ['rpo_artifacts', 'rpo_targets', 'rpo_qualifications', 'connector_operations'];

    public function up(): void
    {
        foreach ($this->tables as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'classification')) {
                Schema::table($t, function (Blueprint $b) {
                    $b->string('classification', 16)->nullable(); // test | demo | operational (null = operational)
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $t) {
            if (Schema::hasColumn($t, 'classification')) {
                Schema::table($t, fn (Blueprint $b) => $b->dropColumn('classification'));
            }
        }
    }
};
