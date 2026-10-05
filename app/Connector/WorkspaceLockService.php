<?php

namespace App\Connector;

use App\Models\ConnectorWorkspaceLock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * CP-PREPHYSICAL — gerente ÚNICO do lock cross-producer de workspace físico (Compile ∪ Patch). NÃO há lock
 * paralelo: Compile e Patch usam a MESMA tabela connector_workspace_locks e a MESMA semântica de exclusão.
 *
 * Contrato: 1 execução MUTÁVEL ativa por (environment, workspace_unit_id), INDEPENDENTE do producer. Autoridade
 * por `execution_id` + `fence_token` (monotônico) + `lease`. Só o detentor ATUAL (fence == lock ativo && lease
 * válida) atravessa o effect barrier. Lease expirada PRÉ-barreira → reapável; PÓS-barreira (barrier_crossed) →
 * indeterminate que SEGURA o workspace (não devolve autoridade sem resolução). Zero física TOTVS.
 */
class WorkspaceLockService
{
    /** Adquire o lock. Erros: workspace_busy (detentor vivo/corrida) | workspace_indeterminate (segurado). */
    public function acquire(int $envId, string $workspaceUnit, string $executionId, string $producer, int $leaseSeconds): array
    {
        return DB::transaction(function () use ($envId, $workspaceUnit, $executionId, $producer, $leaseSeconds) {
            $active = ConnectorWorkspaceLock::where('environment_id', $envId)->where('workspace_unit_id', $workspaceUnit)
                ->where('status', ConnectorWorkspaceLock::ST_ACTIVE)->lockForUpdate()->first();
            $now = now();
            if ($active) {
                if ($active->reconcile_required) {
                    return ['ok' => false, 'error' => 'workspace_indeterminate'];
                }
                if ($active->lease_expires_at && $active->lease_expires_at->gt($now)) {
                    return ['ok' => false, 'error' => 'workspace_busy']; // detentor vivo (lease válida)
                }
                // Lease expirada: PÓS-barreira → indeterminate (segura); PRÉ-barreira → reapável.
                if ($active->barrier_crossed) {
                    $active->update(['reconcile_required' => true]);
                    return ['ok' => false, 'error' => 'workspace_indeterminate'];
                }
                $active->update(['status' => ConnectorWorkspaceLock::ST_RELEASED, 'released_at' => $now]); // reap pré-barreira
            }
            $maxFence = (int) ConnectorWorkspaceLock::where('environment_id', $envId)->where('workspace_unit_id', $workspaceUnit)->max('fence_token');
            try {
                $lock = ConnectorWorkspaceLock::create([
                    'environment_id' => $envId, 'workspace_unit_id' => $workspaceUnit, 'producer' => $producer,
                    'execution_ref' => $executionId, 'status' => ConnectorWorkspaceLock::ST_ACTIVE, 'fence_token' => $maxFence + 1,
                    'barrier_crossed' => false, 'acquired_at' => $now, 'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                ]);
            } catch (QueryException $e) {
                return ['ok' => false, 'error' => 'workspace_busy']; // índice parcial UNIQUE ACTIVE → corrida fail-closed
            }
            return ['ok' => true, 'lock' => $lock];
        });
    }

    /** Só o detentor ATUAL da autoridade (owner + fence + lease) atravessa o barrier. */
    public function validateFence(int $envId, string $workspaceUnit, string $executionId, int $fenceToken): bool
    {
        $lock = ConnectorWorkspaceLock::where('environment_id', $envId)->where('workspace_unit_id', $workspaceUnit)
            ->where('status', ConnectorWorkspaceLock::ST_ACTIVE)->first();
        return $lock
            && (string) $lock->execution_ref === (string) $executionId
            && (int) $lock->fence_token === (int) $fenceToken
            && $lock->lease_expires_at && $lock->lease_expires_at->gt(now());
    }

    /** Marca a travessia do effect barrier (producer-agnóstico) + renova lease. */
    public function markBarrier(?int $lockId, int $leaseSeconds): void
    {
        if (! $lockId) { return; }
        ConnectorWorkspaceLock::whereKey($lockId)->where('status', ConnectorWorkspaceLock::ST_ACTIVE)
            ->update(['barrier_crossed' => true, 'lease_expires_at' => now()->addSeconds($leaseSeconds)]);
    }

    public function extendLease(?int $lockId, int $leaseSeconds): void
    {
        if (! $lockId) { return; }
        ConnectorWorkspaceLock::whereKey($lockId)->where('status', ConnectorWorkspaceLock::ST_ACTIVE)
            ->update(['lease_expires_at' => now()->addSeconds($leaseSeconds)]);
    }

    /** Libera (terminal) ou segura (indeterminate). NUNCA liberar por timeout de transporte. */
    public function release(?int $lockId, bool $reconcileRequired = false): void
    {
        if (! $lockId) { return; }
        ConnectorWorkspaceLock::whereKey($lockId)->where('status', ConnectorWorkspaceLock::ST_ACTIVE)
            ->update($reconcileRequired
                ? ['reconcile_required' => true]
                : ['status' => ConnectorWorkspaceLock::ST_RELEASED, 'released_at' => now()]);
    }
}
