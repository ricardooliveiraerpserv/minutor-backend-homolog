<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log append-only de alterações do realizado mensal de um KR.
 */
class OkrResultChangeLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'okr_key_result_id', 'year_month', 'field_name', 'old_value', 'new_value', 'changed_by', 'reason', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
