<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Connector-4.1 — operação destrutiva/controlada (nesta fase só 'start'). Classe SEPARADA do C-3.
 * execution_id imutável; at-most-once no efeito; autoridade final do desfecho = C-2 observado.
 */
class ConnectorOperation extends Model
{
    protected $table = 'connector_operations';

    protected $guarded = ['id'];

    protected $casts = [
        'precondition_snapshot'      => 'array',
        'postimage_snapshot'         => 'array',
        'approved_at'                => 'datetime',
        'dispatchable_at'            => 'datetime',
        'transport_lease_expires_at' => 'datetime',
        'claimed_at'                 => 'datetime',
        'execution_committed_at'     => 'datetime',
        'executing_at'               => 'datetime',
        'effect_started_at'          => 'datetime',
        'operational_deadline_at'    => 'datetime',
        'agent_result_at'            => 'datetime',
        'reconciled_at'              => 'datetime',
    ];

    // Terminais liberam o alvo/ambiente. Tudo o mais está VIVO (bloqueia nova destrutiva no alvo e no ambiente).
    public const TERMINAL = ['failed', 'expired', 'canceled', 'rejected', 'reconciled_success', 'reconciled_noop'];

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }
}
