<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovideskOrganization extends Model
{
    protected $fillable = ['movidesk_id', 'name', 'cnpj', 'is_active', 'customer_id'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
