<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Segredo do cofre de ambientes: `data` = AES-GCM(vaultKey, segredo) cifrado no CLIENT.
 * Servidor trata como TEXT opaco — NUNCA cast encrypted, NUNCA sai em listagem, só via
 * /reveal enforced. `vault_id` diz qual vaultKey decifra.
 */
class EnvSecret extends Model
{
    use SoftDeletes;

    protected $table = 'env_secrets';

    protected $fillable = [
        'environment_id', 'vault_id', 'kind', 'data', 'key_version', 'critical', 'created_by', 'updated_by',
    ];

    protected $casts = ['critical' => 'boolean', 'key_version' => 'integer'];

    // data nunca deve serializar por acidente numa listagem
    protected $hidden = ['data'];

    public function environment(): BelongsTo { return $this->belongsTo(EnvEnvironment::class, 'environment_id'); }
    public function vault(): BelongsTo { return $this->belongsTo(Vault::class); }
}
