<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CP-PREPHYSICAL — integra C6 (Compile) ao MESMO connector_workspace_locks do Patch (cross-producer). Adiciona:
 *  - lock.barrier_crossed: estado de barreira PRODUCER-AGNÓSTICO (lease expirada + barrier_crossed → indeterminate;
 *    caso contrário reapável). Substitui a introspecção da tabela do produtor (Patch) → funciona p/ Compile também.
 *  - compile_requests.workspace_unit_id (opaco, opcional): quando presente, Compile participa da exclusão física.
 *  - compile_executions.{workspace_unit_id, fence_token, lock_id}: fence do Compile (espelha patch_executions).
 * NÃO habilita física; NÃO altera semântica do C6. Zero TOTVS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('connector_workspace_locks') && ! Schema::hasColumn('connector_workspace_locks', 'barrier_crossed')) {
            Schema::table('connector_workspace_locks', function (Blueprint $t) {
                $t->boolean('barrier_crossed')->default(false); // efeito potencialmente iniciado (producer-agnóstico)
            });
        }
        if (Schema::hasTable('compile_requests') && ! Schema::hasColumn('compile_requests', 'workspace_unit_id')) {
            Schema::table('compile_requests', function (Blueprint $t) {
                $t->string('workspace_unit_id', 80)->nullable(); // OPACO/agent-derived; presente => participa do lock
            });
        }
        if (Schema::hasTable('compile_executions')) {
            Schema::table('compile_executions', function (Blueprint $t) {
                if (! Schema::hasColumn('compile_executions', 'workspace_unit_id')) { $t->string('workspace_unit_id', 80)->nullable(); }
                if (! Schema::hasColumn('compile_executions', 'fence_token')) { $t->unsignedBigInteger('fence_token')->nullable(); }
                if (! Schema::hasColumn('compile_executions', 'lock_id')) { $t->unsignedBigInteger('lock_id')->nullable(); }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('connector_workspace_locks', 'barrier_crossed')) {
            Schema::table('connector_workspace_locks', fn (Blueprint $t) => $t->dropColumn('barrier_crossed'));
        }
        if (Schema::hasColumn('compile_requests', 'workspace_unit_id')) {
            Schema::table('compile_requests', fn (Blueprint $t) => $t->dropColumn('workspace_unit_id'));
        }
        if (Schema::hasTable('compile_executions')) {
            Schema::table('compile_executions', function (Blueprint $t) {
                foreach (['workspace_unit_id', 'fence_token', 'lock_id'] as $c) {
                    if (Schema::hasColumn('compile_executions', $c)) { $t->dropColumn($c); }
                }
            });
        }
    }
};
