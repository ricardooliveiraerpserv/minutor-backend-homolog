<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        // Ambiente que a SUSTENTAÇÃO do cliente utiliza (a "base do suporte").
        'is_support_base',
        'inventory', 'notes', 'responsible_user_id', 'company_id',
        'rdp_host', 'rdp_port',
    ];

    protected $casts = ['inventory' => 'array', 'rdp_port' => 'integer', 'is_support_base' => 'boolean'];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function vault(): BelongsTo { return $this->belongsTo(Vault::class); }
    public function responsible(): BelongsTo { return $this->belongsTo(User::class, 'responsible_user_id'); }
    public function credentials(): HasMany { return $this->hasMany(EnvCredential::class, 'environment_id'); }
    public function secrets(): HasMany { return $this->hasMany(EnvSecret::class, 'environment_id'); }

    /** Projetos sendo desenvolvidos NESTE ambiente (pivot project_environment). */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_environment', 'environment_id', 'project_id')->withTimestamps();
    }
}
