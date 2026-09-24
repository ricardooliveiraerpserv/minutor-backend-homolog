<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceDocSourceRequest extends Model
{
    protected $table = 'source_doc_source_requests';

    protected $fillable = ['customer_id', 'repository', 'ticket', 'priority', 'scope_type', 'paths', 'note', 'status', 'requested_by'];

    protected $casts = ['paths' => 'array'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
