<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomField extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'context',
        'label',
        'key',
        'type',
        'required',
        'options',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'required' => 'boolean',
        'options' => 'array',
    ];

    /**
     * Get the user who created the custom field.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all values for this custom field.
     */
    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /**
     * Scope a query to only include fields of a given context.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $context
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForContext($query, string $context)
    {
        return $query->where('context', $context);
    }
}

