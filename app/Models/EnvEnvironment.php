<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Ambiente de um cliente (Produção/Homolog/Dev/DR). Metadados em CLARO. */
class EnvEnvironment extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $table = 'env_environments';

    public const TYPES = ['prod', 'homolog', 'dev', 'dr'];

    protected $fillable = [
        'customer_id', 'vault_id', 'name', 'type', 'status',
        'inventory', 'notes', 'responsible_user_id', 'company_id',
    ];

    protected $casts = ['inventory' => 'array'];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function vault(): BelongsTo { return $this->belongsTo(Vault::class); }
    public function responsible(): BelongsTo { return $this->belongsTo(User::class, 'responsible_user_id'); }
    public function credentials(): HasMany { return $this->hasMany(EnvCredential::class, 'environment_id'); }
    public function secrets(): HasMany { return $this->hasMany(EnvSecret::class, 'environment_id'); }
}
