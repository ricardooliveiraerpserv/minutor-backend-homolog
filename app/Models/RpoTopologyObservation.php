<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RPO-DISCOVERY D1 — snapshot versionado da topologia RPO observada (realidade física sanitizada).
 * NÃO é autoridade de execução (capability é separada). members[] = {appserver_ref, environment_name,
 * role, role_source, publish_unit_id, rpo_hash, up, process_instance_id, service_name}.
 */
class RpoTopologyObservation extends Model
{
    protected $table = 'rpo_topology_observations';
    protected $guarded = ['id'];
    protected $casts = [
        'members' => 'array',
        'agent_observed_at' => 'datetime',
        'backend_received_at' => 'datetime',
    ];
}
