<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMessageAttachment extends Model
{
    protected $fillable = ['message_id', 'original_name', 'file_path', 'file_size', 'mime_type'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ProjectMessage::class, 'message_id');
    }
}
