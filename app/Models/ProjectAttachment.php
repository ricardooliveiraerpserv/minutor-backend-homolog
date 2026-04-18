<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectAttachment extends Model
{
    protected $fillable = ['project_id', 'contract_attachment_id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contractAttachment(): BelongsTo
    {
        return $this->belongsTo(ContractAttachment::class);
    }
}
