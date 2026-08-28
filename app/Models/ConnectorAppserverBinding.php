<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ENV-HUB — vínculo cadastral (env_appserver_id) ↔ observado (appserver_ref). Status persistido SÓ active|
 * superseded; estados como divergent/not_observed são DERIVADOS na projeção (operational-status), nunca aqui.
 */
class ConnectorAppserverBinding extends Model
{
    protected $table = 'connector_appserver_bindings';
    protected $guarded = ['id'];
    protected $casts = [
        'bound_at' => 'datetime', 'superseded_at' => 'datetime', 'last_observed_at' => 'datetime',
    ];

    public const ST_ACTIVE = 'active';
    public const ST_SUPERSEDED = 'superseded';
}
