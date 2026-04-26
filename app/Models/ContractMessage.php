<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractMessage extends Model
{
    protected $fillable = ['contract_id', 'user_id', 'message', 'visibility'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ContractMessageAttachment::class, 'message_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(ContractMessageRead::class, 'message_id');
    }
}
