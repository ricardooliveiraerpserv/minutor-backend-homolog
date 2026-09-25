<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cofre de senhas: 'personal' (1 por usuário) ou 'shared' (por grupo, membros com wrap RSA). */
class Vault extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'type', 'name', 'created_by', 'key_version', 'pending_rotation'];

    protected $casts = [
        'key_version'      => 'integer',
        'pending_rotation' => 'boolean',
    ];

    public function members(): HasMany { return $this->hasMany(VaultMember::class); }
    public function items(): HasMany { return $this->hasMany(VaultItem::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function memberFor(int $userId): ?VaultMember
    {
        return $this->members->firstWhere('user_id', $userId)
            ?? $this->members()->where('user_id', $userId)->first();
    }
}
