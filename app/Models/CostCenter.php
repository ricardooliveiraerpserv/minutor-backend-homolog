<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Centro de custo cadastrado por CLIENTE (código + descrição — os do cliente).
 * Usado no rateio do projeto (% por centro de custo sobre o valor total do projeto).
 */
class CostCenter extends Model
{
    use SoftDeletes;

    protected $fillable = ['customer_id', 'code', 'description', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ProjectCostCenterAllocation::class);
    }
}
