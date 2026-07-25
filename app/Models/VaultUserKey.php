<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perfil criptográfico do usuário no Cofre (zero-knowledge).
 * encrypted_* são blobs cifrados NO CLIENT — o servidor não decifra (TEXT opaco).
 * Única exceção: totp_secret (2FA server-side, cast encrypted, nunca serializa).
 */
class VaultUserKey extends Model
{
    protected $fillable = [
        'user_id', 'kdf_iterations', 'kdf_memory', 'kdf_parallelism',
        'auth_hash', 'encrypted_symmetric_key', 'public_key', 'encrypted_private_key',
        'recovery_symmetric_key', 'totp_secret', 'totp_confirmed_at', 'totp_last_timestep',
    ];

    // auth_hash (bcrypt), totp_secret e token de recovery nunca voltam na API
    protected $hidden = ['auth_hash', 'totp_secret', 'recovery_token_hash'];

    protected $casts = [
        'totp_secret'               => 'encrypted',
        'totp_confirmed_at'         => 'datetime',
        'totp_last_timestep'        => 'integer',
        'recovery_token_expires_at' => 'datetime',
        'kdf_iterations'            => 'integer',
        'kdf_memory'                => 'integer',
        'kdf_parallelism'           => 'integer',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function isConfigured(): bool
    {
        return !empty($this->attributes['auth_hash']) && !empty($this->attributes['encrypted_symmetric_key']);
    }

    public function totpConfirmed(): bool
    {
        return $this->totp_confirmed_at !== null;
    }
}
