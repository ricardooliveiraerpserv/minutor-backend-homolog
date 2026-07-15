<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 3 — company_id nas tabelas de FECHAMENTO/FOLHA/ADIANTAMENTO (separadas por
 * empresa). EXCLUI `fechamento_diretoria`/`_itens` (lógica CONJUNTA ERPSERV+BIZIFY,
 * fica cross-empresa) e `fechamento_email_templates` (config compartilhada).
 * Backfill → ERPSERV. Coluna aditiva; isolamento segue gated pela flag.
 */
return new class extends Migration
{
    private array $tables = [
        'adiantamentos', 'adiantamento_parcelas',
        'fechamento_administrativos', 'fechamento_clientes', 'fechamento_consultor_ajustes',
        'fechamento_consultor_emails', 'fechamento_folha', 'fechamento_notas',
        'fechamento_parceiro_ajustes', 'fechamento_parceiros', 'fechamento_send_status',
    ];

    public function up(): void
    {
        $erpservId = DB::table('companies')->where('slug', 'erpserv')->value('id');

        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $t->index('company_id');
            });
            if ($erpservId) {
                DB::table($table)->whereNull('company_id')->update(['company_id' => $erpservId]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropConstrainedForeignId('company_id');
                });
            }
        }
    }
};
