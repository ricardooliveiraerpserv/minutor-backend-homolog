<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractKanbanLog extends Model
{
    protected $fillable = ['contract_id', 'from_column', 'to_column', 'moved_by_id', 'coordinator_id', 'notes'];

    public function contract(): BelongsTo { return $this->belongsTo(Contract::class); }
    public function movedBy(): BelongsTo  { return $this->belongsTo(User::class, 'moved_by_id'); }
    public function coordinator(): BelongsTo { return $this->belongsTo(User::class, 'coordinator_id'); }
}
