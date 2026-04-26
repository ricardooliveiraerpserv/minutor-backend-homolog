<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractMessageRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['message_id', 'user_id'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ContractMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
