<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Config REST AdvPL (RPO) por ambiente. Espelha o config do ProSight enviado.
 * A senha é cifrada em repouso (cast 'encrypted') — o servidor decifra para consultar o RPO;
 * NUNCA é serializada ao FE (o controller só devolve rpo_api_password_set).
 */
class ProsightRpoConfig extends Model
{
    protected $table = 'prosight_rpo_configs';

    protected $fillable = [
        'environment_id', 'rpo_api_url', 'rpo_api_user', 'rpo_api_password',
        'rpo_exclusion_patterns', 'allow_insecure_tls', 'updated_by',
        'last_scan_summary', 'last_scan_at',
    ];

    protected $hidden = ['rpo_api_password'];

    protected $casts = [
        'rpo_api_password' => 'encrypted',
        'allow_insecure_tls' => 'boolean',
        'last_scan_summary' => 'array',
        'last_scan_at' => 'datetime',
    ];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(EnvEnvironment::class, 'environment_id');
    }
}
