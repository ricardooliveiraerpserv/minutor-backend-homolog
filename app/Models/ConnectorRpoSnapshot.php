<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Conector-2 — histórico de RPO OBSERVADO (append SÓ em mudança de sha256). Identidade = rpo_hash.
 * Nunca guarda bytes/path do RPO — só metadados observados.
 */
class ConnectorRpoSnapshot extends Model
{
    protected $fillable = [
        'environment_id', 'appserver_ref', 'rpo_hash', 'rpo_version', 'size_bytes', 'mtime', 'observed_at',
    ];

    protected $casts = [
        'mtime'       => 'datetime',
        'observed_at' => 'datetime',
    ];
}
