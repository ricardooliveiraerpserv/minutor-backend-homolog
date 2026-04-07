<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConsultantGroup extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'active',
        'created_by',
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
        'deleted_at' => 'datetime',
    ];

    /**
     * Relacionamento com os usuários (consultores) do grupo
     */
    public function consultants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'consultant_group_user')
                    ->withTimestamps();
    }

    /**
     * Relacionamento com o usuário que criou o grupo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope para filtrar apenas grupos ativos
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Verifica se o grupo possui consultores
     */
    public function hasConsultants(): bool
    {
        return $this->consultants()->count() > 0;
    }

    /**
     * Obtém o número de consultores no grupo
     */
    public function getConsultantsCountAttribute(): int
    {
        return $this->consultants()->count();
    }
}

