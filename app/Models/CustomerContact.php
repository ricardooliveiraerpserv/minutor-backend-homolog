<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerContact extends Model
{
    protected $fillable = ['customer_id', 'name', 'cargo', 'email', 'phone', 'recebe_alerta_consumo'];

    protected $casts = ['recebe_alerta_consumo' => 'boolean'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
