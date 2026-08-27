<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Connector-3 — comando assíncrono (não destrutivo). Fila persistente consumida pelo agente
 * (long-poll). Estados terminais: succeeded|failed|expired|canceled (imutáveis).
 */
class ConnectorCommand extends Model
{
    protected $table = 'connector_commands';

    protected $fillable = [
        'environment_id', 'customer_id', 'command_type', 'params', 'status', 'idempotency_key',
        'attempts', 'max_attempts', 'requested_by', 'claimed_by_agent_id', 'claim_token',
        'claim_expires_at', 'available_at', 'expires_at', 'inventory_applied_at',
        'result_outcome', 'result_detail', 'result_meta',
        'enqueued_at', 'claimed_at', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'params'               => 'array',
        'result_meta'          => 'array',
        'claim_expires_at'     => 'datetime',
        'available_at'         => 'datetime',
        'expires_at'           => 'datetime',
        'inventory_applied_at' => 'datetime',
        'enqueued_at'          => 'datetime',
        'claimed_at'           => 'datetime',
        'started_at'           => 'datetime',
        'finished_at'          => 'datetime',
    ];

    public const TERMINAL = ['succeeded', 'failed', 'expired', 'canceled'];
    public const IN_FLIGHT = ['queued', 'claimed', 'running'];

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }
}
