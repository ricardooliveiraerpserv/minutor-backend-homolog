<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Membro de um cofre. encrypted_vault_key = RSA-OAEP(pubKey do membro, vaultKey) — opaco. */
class VaultMember extends Model
{
    public const ROLES = ['admin', 'write', 'read'];

    protected $fillable = ['vault_id', 'user_id', 'role', 'encrypted_vault_key', 'key_version'];

    protected $casts = ['key_version' => 'integer'];

    public function vault(): BelongsTo { return $this->belongsTo(Vault::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function canWrite(): bool { return in_array($this->role, ['admin', 'write'], true); }
    public function isVaultAdmin(): bool { return $this->role === 'admin'; }
}
