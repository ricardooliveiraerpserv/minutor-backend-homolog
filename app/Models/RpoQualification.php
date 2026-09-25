<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** C5.1 — qualificação CONTEXTUAL known_good (artifact × target). known_good = revoked_at IS NULL. */
class RpoQualification extends Model
{
    protected $table = 'rpo_qualifications';
    protected $guarded = ['id'];
    protected $casts = ['qualified_at' => 'datetime', 'revoked_at' => 'datetime'];
}
