<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** VPN de um ambiente (Fortinet/OpenVPN). Metadados em CLARO; senha é segredo. */
class EnvVpn extends Model
{
    use SoftDeletes;

    protected $table = 'env_vpns';

    protected $fillable = [
        'environment_id', 'provider', 'server', 'port', 'group', 'username',
        'password_secret_id', 'critical', 'notes', 'created_by',
    ];

    protected $casts = ['critical' => 'boolean', 'port' => 'integer'];

    public function environment(): BelongsTo { return $this->belongsTo(EnvEnvironment::class, 'environment_id'); }
}
