<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractRequestMessage extends Model
{
    protected $fillable = ['contract_request_id', 'user_id', 'message'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ContractRequest::class, 'contract_request_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
