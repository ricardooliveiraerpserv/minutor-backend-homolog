<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PATCH P2 — máquina governada de execução (simulated). FENCING no workspace lock (fence_token+lease: só o
 * detentor atual da autoridade atravessa o barrier; UNIQUE ACTIVE sozinho não resolve crash/lease). Journal
 * durável por item. Immutable pin na execução. Causal reconcile. P2 NÃO faz física TOTVS; Live unavailable.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fencing no lock cross-producer (criado em P1).
        if (Schema::hasTable('connector_workspace_locks')) {
            Schema::table('connector_workspace_locks', function (Blueprint $t) {
                if (! Schema::hasColumn('connector_workspace_locks', 'fence_token')) {
                    $t->unsignedBigInteger('fence_token')->default(0); // monotônico por workspace_unit — token de autoridade
                }
                if (! Schema::hasColumn('connector_workspace_locks', 'lease_expires_at')) {
                    $t->timestamp('lease_expires_at')->nullable();
                }
                if (! Schema::hasColumn('connector_workspace_locks', 'reconcile_required')) {
                    $t->boolean('reconcile_required')->default(false); // indeterminate segura o workspace
                }
            });
        }

        // Immutable pin + fence + resultado reportado na execução.
        if (Schema::hasTable('patch_executions')) {
            Schema::table('patch_executions', function (Blueprint $t) {
                foreach ([
                    'fence_token' => fn () => $t->unsignedBigInteger('fence_token')->nullable(),
                    'lock_id' => fn () => $t->unsignedBigInteger('lock_id')->nullable(),
                    'base_rpo_hash' => fn () => $t->string('base_rpo_hash', 64)->nullable(),   // PIN (imutável pós-barrier)
                    'batch_digest' => fn () => $t->string('batch_digest', 64)->nullable(),     // PIN
                    'capability_adapter_version' => fn () => $t->string('capability_adapter_version', 60)->nullable(),
                    'candidate_digest' => fn () => $t->string('candidate_digest', 64)->nullable(), // REPORTADO
                    'applied_items' => fn () => $t->unsignedInteger('applied_items')->nullable(),
                    'reconciliation_state' => fn () => $t->string('reconciliation_state', 24)->nullable(),
                ] as $col => $add) {
                    if (! Schema::hasColumn('patch_executions', $col)) { $add(); }
                }
            });
        }

        // Journal durável POR ITEM (ordem + started/committed) — determina até onde chegou após perda de comunicação.
        if (! Schema::hasTable('patch_execution_items')) {
            Schema::create('patch_execution_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('patch_execution_id');
                $t->unsignedInteger('batch_order');
                $t->unsignedBigInteger('patch_input_id');
                $t->string('item_digest', 64);       // PIN do item
                $t->string('status', 12)->default('pending'); // pending|started|committed|failed
                $t->timestamp('started_at')->nullable();
                $t->timestamp('committed_at')->nullable();
                $t->timestamps();
                $t->unique(['patch_execution_id', 'batch_order']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('patch_execution_items');
        if (Schema::hasTable('patch_executions')) {
            Schema::table('patch_executions', function (Blueprint $t) {
                foreach (['fence_token', 'lock_id', 'base_rpo_hash', 'batch_digest', 'capability_adapter_version', 'candidate_digest', 'applied_items', 'reconciliation_state'] as $c) {
                    if (Schema::hasColumn('patch_executions', $c)) { $t->dropColumn($c); }
                }
            });
        }
        if (Schema::hasTable('connector_workspace_locks')) {
            Schema::table('connector_workspace_locks', function (Blueprint $t) {
                foreach (['fence_token', 'lease_expires_at', 'reconcile_required'] as $c) {
                    if (Schema::hasColumn('connector_workspace_locks', $c)) { $t->dropColumn($c); }
                }
            });
        }
    }
};
