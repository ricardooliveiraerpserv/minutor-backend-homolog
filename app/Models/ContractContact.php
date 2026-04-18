<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractContact extends Model
{
    protected $fillable = ['contract_id', 'name', 'cargo', 'email', 'phone'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
