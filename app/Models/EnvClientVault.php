<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Mapeia um Cliente ao seu vault dedicado (vaults type='client'). */
class EnvClientVault extends Model
{
    protected $fillable = ['customer_id', 'vault_id', 'created_by'];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function vault(): BelongsTo { return $this->belongsTo(Vault::class); }
    public function environments(): HasMany { return $this->hasMany(EnvEnvironment::class, 'customer_id', 'customer_id'); }
}
