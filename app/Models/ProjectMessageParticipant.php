<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMessageParticipant extends Model
{
    protected $table = 'project_message_participants';

    protected $fillable = ['project_id', 'user_id', 'added_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
