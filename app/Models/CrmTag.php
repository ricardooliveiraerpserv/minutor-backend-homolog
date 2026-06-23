<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** CRM — tag/rótulo reutilizável (empresas e, futuramente, oportunidades). */
class CrmTag extends Model
{
    protected $fillable = ['name', 'color'];

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_tag');
    }
}
