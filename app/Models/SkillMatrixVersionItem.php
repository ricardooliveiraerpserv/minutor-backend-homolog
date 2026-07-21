<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item congelado de uma versão da matriz (snapshot de uma competência).
 */
class SkillMatrixVersionItem extends Model
{
    protected $fillable = [
        'matrix_version_id', 'skill_id', 'category', 'name', 'section', 'skill_type', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(SkillMatrixVersion::class, 'matrix_version_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
