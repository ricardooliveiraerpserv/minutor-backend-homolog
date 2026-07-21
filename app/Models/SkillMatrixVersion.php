<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versão da MATRIZ ÚNICA de competências. As respostas ficam vinculadas à
 * versão usada no momento da avaliação (skill_matrix_version_items congela o
 * conjunto de competências).
 */
class SkillMatrixVersion extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'number', 'label', 'status', 'notes', 'skills_count', 'published_at', 'published_by',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'skills_count' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SkillMatrixVersionItem::class, 'matrix_version_id')->orderBy('sort_order');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
