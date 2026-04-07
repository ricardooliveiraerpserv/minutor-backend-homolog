<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomFieldValue extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'custom_field_id',
        'entity_id',
        'value',
    ];

    /**
     * Get the custom field that owns this value.
     */
    public function customField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class);
    }

    /**
     * Get the parsed value based on the field type.
     *
     * @return mixed
     */
    public function getParsedValueAttribute()
    {
        $type = $this->customField->type;

        return match($type) {
            'number' => is_numeric($this->value) ? (float) $this->value : null,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'date' => $this->value,
            'select' => $this->value,
            'text' => $this->value,
            default => $this->value,
        };
    }
}

