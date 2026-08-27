<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Conector-2 — transição SIGNIFICATIVA observada (alimenta a família 'operacoes' da timeline C1).
 * Só é criado em mudança real (AppServer up/down, versão/build, RPO hash, REST health). Heartbeat
 * normal e inventário sem mudança NÃO geram evento. meta/detail sanitizados (sem secret/path).
 */
class ConnectorEvent extends Model
{
    protected $fillable = [
        'environment_id', 'appserver_ref', 'event_type', 'outcome', 'detail', 'meta', 'occurred_at',
    ];

    protected $casts = [
        'meta'        => 'array',
        'occurred_at' => 'datetime',
    ];
}
