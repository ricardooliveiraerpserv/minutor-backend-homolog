<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerContact extends Model
{
    protected $fillable = ['customer_id', 'name', 'cargo', 'email', 'phone',
        // CRM
        'departamento', 'whatsapp', 'linkedin', 'influencia_decisao', 'canal_preferido'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
