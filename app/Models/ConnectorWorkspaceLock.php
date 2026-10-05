<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PATCH/COMPILE — lock CROSS-PRODUCER da unidade física mutável. UNIQUE ACTIVE(env, workspace_unit_id). */
class ConnectorWorkspaceLock extends Model
{
    protected $table = 'connector_workspace_locks';
    protected $guarded = ['id'];
    protected $casts = ['acquired_at' => 'datetime', 'released_at' => 'datetime', 'lease_expires_at' => 'datetime', 'reconcile_required' => 'boolean'];

    public const ST_ACTIVE = 'active';
    public const ST_RELEASED = 'released';
    public const PRODUCER_COMPILE = 'compile';
    public const PRODUCER_PATCH = 'patch';
}
