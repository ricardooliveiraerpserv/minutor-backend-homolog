<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractType extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relacionamento com projetos
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Scope para filtrar apenas tipos ativos
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope para buscar por código
     */
    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    /**
     * Get all active contract types as options
     */
    public static function getActiveOptions(): array
    {
        return static::active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /**
     * Get all active contract types with code as key
     */
    public static function getActiveOptionsWithCode(): array
    {
        return static::active()
            ->orderBy('name')
            ->pluck('name', 'code')
            ->toArray();
    }
}
