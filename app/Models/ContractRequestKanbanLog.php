<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractRequestKanbanLog extends Model
{
    protected $fillable = ['contract_request_id', 'from_column', 'to_column', 'moved_by_id'];

    public function contractRequest(): BelongsTo { return $this->belongsTo(ContractRequest::class); }
    public function movedBy(): BelongsTo         { return $this->belongsTo(User::class, 'moved_by_id'); }
}
