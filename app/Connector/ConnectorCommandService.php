<?php

namespace App\Connector;

use App\Models\ConnectorAgent;
use App\Models\ConnectorCommand;
use App\Models\ConnectorEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connector-3 — orquestração de comandos assíncronos (NÃO destrutivos). Regras travadas:
 *  - ENTREGA AT-LEAST-ONCE (não exactly-once). collect_inventory_now suporta porque o pipeline C-2
 *    é idempotente + anti-reorder. Esta propriedade NÃO autoriza transporte p/ comandos do C-4/C-5.
 *  - attempts INCREMENTA atomicamente NO CLAIM (nunca ultrapassa max_attempts).
 *  - claim exclusivo (FOR UPDATE SKIP LOCKED) + LEASE + claim_token (liga o resultado a UM claim).
 *  - correlação FORTE comando→inventário via inventory_applied_at (setado pelo processor C-2 quando
 *    o inventário traz trigger.command_id do MESMO ambiente/agente) — nunca por ordem temporal.
 *  - cancelamento simples: queued→canceled; claimed/running→409; terminais imutáveis.
 * Toda transição relevante alimenta connector_events (família operacoes da timeline C1). Sem secret/path.
 */
class ConnectorCommandService
{
    private function cfg(string $k, $d = null)
    {
        return config("connector.commands.$k", $d);
    }

    /** Emite evento na timeline C1 (família operacoes). ref sempre null (nível comando). */
    private function emit(int $envId, string $type, string $outcome, ?string $detail, array $meta): void
    {
        ConnectorEvent::create([
            'environment_id' => $envId, 'appserver_ref' => null, 'event_type' => $type,
            'outcome' => $outcome, 'detail' => $detail, 'meta' => $meta, 'occurred_at' => now(),
        ]);
    }

    /**
     * Enfileira um comando. Coalescing: no máximo 1 em-voo por (ambiente, tipo) — duplicado retorna o
     * vigente (não cria 2º comando, evita tempestade de coletas). command_type já validado (allowlist).
     * @return array{command: ConnectorCommand, coalesced: bool}
     */
    public function enqueue(int $envId, ?int $customerId, string $type, ?int $requestedBy, ?string $idempotencyKey): array
    {
        // Cap de em-voo = 1 por (env, tipo): duplicado coalesce no vigente.
        $existing = ConnectorCommand::where('environment_id', $envId)->where('command_type', $type)
            ->whereIn('status', ConnectorCommand::IN_FLIGHT)->orderByDesc('id')->first();
        if ($existing) {
            return ['command' => $existing, 'coalesced' => true];
        }

        // Sem idem-key explícita → deriva chave de DEBOUNCE (janela) p/ coalescer repetições rápidas.
        $key = $idempotencyKey;
        if ($key === null) {
            $window = (int) $this->cfg('debounce', 30);
            $bucket = $window > 0 ? intdiv(time(), $window) : time();
            $key = substr(hash('sha256', "$type|$envId|$bucket"), 0, 40);
        }

        try {
            $cmd = ConnectorCommand::create([
                'environment_id' => $envId, 'customer_id' => $customerId, 'command_type' => $type,
                'params' => [], 'status' => 'queued', 'idempotency_key' => $key,
                'attempts' => 0, 'max_attempts' => (int) $this->cfg('max_attempts', 2),
                'requested_by' => $requestedBy,
                'expires_at' => now()->addSeconds((int) $this->cfg('ttl', 120)),
                'enqueued_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Corrida na mesma janela/idem-key → coalesce no em-voo existente.
            $cmd = ConnectorCommand::where('environment_id', $envId)->where('idempotency_key', $key)
                ->whereIn('status', ConnectorCommand::IN_FLIGHT)->orderByDesc('id')->first();
            if ($cmd) {
                return ['command' => $cmd, 'coalesced' => true];
            }
            throw new \RuntimeException('enqueue_conflict');
        }

        $this->emit($envId, 'command_enqueued', 'info', "Comando {$type} solicitado", [
            'command_type' => $type, 'command_id' => $cmd->id,
        ]);

        return ['command' => $cmd, 'coalesced' => false];
    }

    /**
     * Reaper LAZY (sem depender de scheduler): leases perdidos → re-enfileira (se attempts<max) ou expira;
     * queued com TTL vencido → expira. Chamado antes de claim/list. Idempotente.
     */
    public function reapEnvironment(int $envId): void
    {
        $now = now();
        // (a) claims vencidos (claimed/running com lease no passado).
        $stale = ConnectorCommand::where('environment_id', $envId)
            ->whereIn('status', ['claimed', 'running'])
            ->whereNotNull('claim_expires_at')->where('claim_expires_at', '<=', $now)->get();
        foreach ($stale as $cmd) {
            DB::transaction(function () use ($cmd) {
                $row = ConnectorCommand::whereKey($cmd->id)->lockForUpdate()->first();
                if (! $row || ! in_array($row->status, ['claimed', 'running'], true)) {
                    return;
                }
                if ($row->claim_expires_at && $row->claim_expires_at->isFuture()) {
                    return; // renovado nesse meio-tempo
                }
                if ($row->attempts < $row->max_attempts) {
                    $row->update([
                        'status' => 'queued', 'claimed_by_agent_id' => null, 'claim_token' => null,
                        'claim_expires_at' => null, 'available_at' => now(),
                    ]);
                    $this->emit((int) $row->environment_id, 'command_claim_expired', 'fail', 'Lease perdido — reenfileirado', [
                        'command_type' => $row->command_type, 'command_id' => $row->id, 'attempt' => $row->attempts,
                    ]);
                } else {
                    $row->update(['status' => 'expired', 'finished_at' => now()]);
                    $this->emit((int) $row->environment_id, 'command_expired', 'fail', 'Comando expirado (retries esgotados)', [
                        'command_type' => $row->command_type, 'command_id' => $row->id, 'attempt' => $row->attempts,
                    ]);
                }
            });
        }
        // (b) queued com TTL duro vencido (nunca reivindicado a tempo).
        $expired = ConnectorCommand::where('environment_id', $envId)->where('status', 'queued')
            ->where('expires_at', '<=', $now)->get();
        foreach ($expired as $cmd) {
            DB::transaction(function () use ($cmd) {
                $row = ConnectorCommand::whereKey($cmd->id)->lockForUpdate()->first();
                if (! $row || $row->status !== 'queued' || $row->expires_at->isFuture()) {
                    return;
                }
                $row->update(['status' => 'expired', 'finished_at' => now()]);
                $this->emit((int) $row->environment_id, 'command_expired', 'fail', 'Comando expirado (TTL sem claim)', [
                    'command_type' => $row->command_type, 'command_id' => $row->id, 'attempt' => $row->attempts,
                ]);
            });
        }
    }

    /**
     * Claim ATÔMICO de 1 comando elegível do ambiente do agente. attempts++ NO CLAIM (uma vez, mesmo sob
     * concorrência — FOR UPDATE SKIP LOCKED). Retorna o comando reivindicado (com claim_token) ou null.
     */
    public function claimNext(ConnectorAgent $agent): ?ConnectorCommand
    {
        $envId = (int) $agent->environment_id;
        $token = bin2hex(random_bytes(24)); // 48 hex chars ≤ claim_token(64)
        $lease = (int) $this->cfg('claim_lease', 15);

        $row = DB::selectOne(
            "UPDATE connector_commands SET
                status='claimed', attempts=attempts+1, claimed_by_agent_id=?, claim_token=?,
                claimed_at=now(), claim_expires_at=now() + (? * interval '1 second'), updated_at=now()
             WHERE id = (
                SELECT id FROM connector_commands
                 WHERE environment_id=? AND status='queued'
                   AND (available_at IS NULL OR available_at <= now())
                   AND attempts < max_attempts
                 ORDER BY created_at
                 FOR UPDATE SKIP LOCKED
                 LIMIT 1
             )
             RETURNING id",
            [$agent->agent_id, $token, $lease, $envId]
        );
        if (! $row) {
            return null;
        }
        $cmd = ConnectorCommand::find($row->id);
        $this->emit($envId, 'command_claimed', 'info', "Comando {$cmd->command_type} reivindicado", [
            'command_type' => $cmd->command_type, 'command_id' => $cmd->id, 'attempt' => $cmd->attempts,
        ]);

        return $cmd;
    }

    /** ACK: claimed→running (opcional; observabilidade). Requer claim_token do claim vigente. */
    public function ack(ConnectorAgent $agent, ConnectorCommand $cmd, string $claimToken): bool
    {
        return (bool) DB::transaction(function () use ($agent, $cmd, $claimToken) {
            $row = ConnectorCommand::whereKey($cmd->id)->lockForUpdate()->first();
            if (! $row || (int) $row->environment_id !== (int) $agent->environment_id) {
                return false;
            }
            if ($row->status !== 'claimed' || $row->claim_token !== $claimToken) {
                return false;
            }
            $row->update(['status' => 'running', 'started_at' => now()]);
            $this->emit((int) $row->environment_id, 'command_running', 'info', "Comando {$row->command_type} em execução", [
                'command_type' => $row->command_type, 'command_id' => $row->id, 'attempt' => $row->attempts,
            ]);

            return true;
        });
    }

    /**
     * Resultado do agente. Aceito SÓ se: mesmo ambiente + status em {claimed,running} + claim_token confere.
     * Caso contrário (terminal / token de claim antigo / re-claimado) → stale (409). Resultado atrasado NUNCA
     * ressuscita nem sobrescreve terminal. Detalhe/erro sanitizados.
     * @return array{ok: bool, status: string}
     */
    public function result(ConnectorAgent $agent, ConnectorCommand $cmd, string $claimToken, string $outcome, ?string $detail, array $meta): array
    {
        return DB::transaction(function () use ($agent, $cmd, $claimToken, $outcome, $detail, $meta) {
            $row = ConnectorCommand::whereKey($cmd->id)->lockForUpdate()->first();
            if (! $row || (int) $row->environment_id !== (int) $agent->environment_id) {
                return ['ok' => false, 'status' => 'not_found'];
            }
            if (! in_array($row->status, ['claimed', 'running'], true) || $row->claim_token !== $claimToken) {
                return ['ok' => false, 'status' => 'stale_result']; // resultado atrasado / claim antigo
            }
            $final = $outcome === 'ok' ? 'succeeded' : 'failed';
            $row->update([
                'status' => $final, 'result_outcome' => $outcome === 'ok' ? 'ok' : 'fail',
                'result_detail' => $detail, 'result_meta' => $meta, 'finished_at' => now(),
            ]);
            $this->emit((int) $row->environment_id, $final === 'succeeded' ? 'command_succeeded' : 'command_failed',
                $final === 'succeeded' ? 'ok' : 'fail', "Comando {$row->command_type} " . ($final === 'succeeded' ? 'concluído' : 'falhou'), [
                    'command_type' => $row->command_type, 'command_id' => $row->id, 'attempt' => $row->attempts,
                    'correlated' => $row->inventory_applied_at !== null, // correlação FORTE (não temporal)
                ]);

            return ['ok' => true, 'status' => $final];
        });
    }

    /**
     * Cancelamento SIMPLES (C-3): queued→canceled; claimed/running→409 (already_running); terminal→409.
     * @return array{ok: bool, status: string}
     */
    public function cancel(ConnectorCommand $cmd): array
    {
        return DB::transaction(function () use ($cmd) {
            $row = ConnectorCommand::whereKey($cmd->id)->lockForUpdate()->first();
            if (! $row) {
                return ['ok' => false, 'status' => 'not_found'];
            }
            if ($row->status === 'queued') {
                $row->update(['status' => 'canceled', 'finished_at' => now()]);
                $this->emit((int) $row->environment_id, 'command_canceled', 'info', "Comando {$row->command_type} cancelado", [
                    'command_type' => $row->command_type, 'command_id' => $row->id,
                ]);

                return ['ok' => true, 'status' => 'canceled'];
            }
            if (in_array($row->status, ['claimed', 'running'], true)) {
                return ['ok' => false, 'status' => 'already_running']; // 409 — sem cancelamento cooperativo no C-3
            }

            return ['ok' => false, 'status' => 'terminal']; // 409 — terminal imutável
        });
    }

    /**
     * Correlação FORTE: registra que um INVENTÁRIO com trigger.command_id foi aplicado p/ ESTE comando.
     * Só vincula se o comando é do MESMO ambiente do agente E foi reivindicado por ESTE agente
     * E está em {claimed,running}. command_id de outro ambiente/agente NÃO correlaciona. Chamado pelo
     * ConnectorInventoryProcessor (pipeline C-2 único), dentro da transação do inventário.
     */
    public function markInventoryApplied(ConnectorAgent $agent, int $commandId, \Illuminate\Support\Carbon $receivedAt): bool
    {
        $row = ConnectorCommand::whereKey($commandId)->lockForUpdate()->first();
        if (! $row || (int) $row->environment_id !== (int) $agent->environment_id) {
            return false;
        }
        if ($row->claimed_by_agent_id !== $agent->agent_id || ! in_array($row->status, ['claimed', 'running'], true)) {
            return false;
        }
        $row->update(['inventory_applied_at' => $receivedAt]);

        return true;
    }

    /** Reaper de TODOS os ambientes com comandos em voo (uso do scheduler). Retorna nº de ambientes varridos. */
    public function reapAll(): int
    {
        $envIds = ConnectorCommand::whereIn('status', ConnectorCommand::IN_FLIGHT)
            ->distinct()->pluck('environment_id');
        foreach ($envIds as $envId) {
            $this->reapEnvironment((int) $envId);
        }

        return $envIds->count();
    }

    /** Poda operacional (a auditoria durável fica em connector_events). Retorna nº removido. */
    public function prune(): int
    {
        $days = (int) $this->cfg('retention_days', 60);

        return ConnectorCommand::whereIn('status', ConnectorCommand::TERMINAL)
            ->where('finished_at', '<', now()->subDays($days))->delete();
    }
}
