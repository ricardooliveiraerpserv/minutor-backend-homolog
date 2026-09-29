<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evento de telemetria de USO (append-only). Ver migration create_usage_events_table.
 * Sem updated_at — é um log imutável de comportamento, não uma entidade editável.
 */
class UsageEvent extends Model
{
    protected $table = 'usage_events';
    public $timestamps = false;

    protected $fillable = [
        'scope', 'feature', 'action', 'user_id',
        'entity_type', 'entity_id', 'work_session_id', 'metadata', 'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];
}
