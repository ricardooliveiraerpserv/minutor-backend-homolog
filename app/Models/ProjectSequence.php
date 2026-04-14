<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectSequence extends Model
{
    protected $fillable = ['customer_id', 'last_sequence'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
