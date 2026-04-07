<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Project;

class ProjectStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Obtém todos os projetos com este status (usando code)
     */
    public function getProjectsAttribute()
    {
        return Project::where('status', $this->code)->get();
    }

    /**
     * Conta quantos projetos usam este status
     */
    public function projectsCount(): int
    {
        return Project::where('status', $this->code)->count();
    }

    /**
     * Scope para status ativos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get all active project statuses with code as key
     */
    public static function getActiveOptionsWithCode(): array
    {
        return static::active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'code')
            ->toArray();
    }
}

