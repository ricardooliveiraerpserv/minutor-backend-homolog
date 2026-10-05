<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Item do cofre. `name` é CLARO (busca); `data` é AES-GCM(vaultKey, JSON) — opaco,
 * contém username/password/url/notes/totp_seed/reprompt. NUNCA logar `data`.
 */
class VaultItem extends Model
{
    use SoftDeletes;

    protected $fillable = ['vault_id', 'type', 'name', 'data', 'key_version', 'created_by', 'updated_by'];

    protected $casts = ['key_version' => 'integer'];

    public function vault(): BelongsTo { return $this->belongsTo(Vault::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
}
