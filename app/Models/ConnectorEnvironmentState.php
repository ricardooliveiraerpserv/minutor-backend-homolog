<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Conector-1 — estado OBSERVADO do ambiente (1 linha/ambiente). last_seen_at é a autoridade de
 * presença (received_at). Sem AppServer/RPO/processo (Connector-2+). Status é derivado, não coluna.
 */
class ConnectorEnvironmentState extends Model
{
    protected $table = 'connector_environment_state';
    protected $primaryKey = 'environment_id';
    public $incrementing = false;

    protected $fillable = [
        'environment_id', 'agent_id', 'last_seen_at', 'last_observed_at', 'clock_offset_s',
        'agent_uptime_s', 'agent_reported_status', 'last_error',
        // Connector-2 — inventário observado (frescor separado da presença C-1).
        'observed_json', 'inventory_received_at', 'inventory_observed_at',
    ];

    protected $casts = [
        'last_seen_at'          => 'datetime',
        'last_observed_at'      => 'datetime',
        'observed_json'         => 'array',
        'inventory_received_at' => 'datetime',
        'inventory_observed_at' => 'datetime',
    ];
}
