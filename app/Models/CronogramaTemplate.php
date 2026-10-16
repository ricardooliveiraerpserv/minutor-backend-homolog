<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CronogramaTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'payload',
        'created_by',
        'active',
    ];

    protected $casts = [
        'payload' => 'array',
        'active'  => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
