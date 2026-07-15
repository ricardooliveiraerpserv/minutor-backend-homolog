<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Empresa do grupo (multi-empresa interno). `type` internal|external prepara o
 * futuro SaaS (empresa externa isolada); por ora só internal.
 */
class Company extends Model
{
    protected $fillable = ['name', 'slug', 'color', 'cnpj', 'type', 'status'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Slug estável a partir do nome (para novas empresas criadas via UI). */
    public static function makeSlug(string $name): string
    {
        $base = \Illuminate\Support\Str::slug($name) ?: 'empresa';
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
