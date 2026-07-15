<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 2 — company_id nas tabelas PILOTO (projects, contracts, timesheets,
 * expenses, tasks). Nullable + FK + índice; backfill de tudo → ERPSERV.
 * O scoping automático (global scope) é GATED por config('multiempresa.scoping_enabled'),
 * então adicionar a coluna aqui NÃO muda comportamento até a flag ligar.
 */
return new class extends Migration
{
    private array $tables = ['projects', 'contracts', 'timesheets', 'expenses', 'tasks'];

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
