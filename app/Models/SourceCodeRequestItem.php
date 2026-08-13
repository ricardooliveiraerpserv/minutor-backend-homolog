<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fase 1 — um fonte solicitado (item independente, com identidade própria p/ a Fase 2). */
class SourceCodeRequestItem extends Model
{
    protected $fillable = [
        'request_id', 'owner', 'repository', 'branch', 'path', 'filename',
        'original_commit_sha', 'original_commit_at', 'commit_author', 'commit_message',
        'attachment_id', 'status', 'error',
        // Fase 2:
        'returned_attachment_id', 'gmud_id', 'new_commit_sha', 'pull_request_url', 'return_status',
    ];

    protected $casts = ['original_commit_at' => 'datetime'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(SourceCodeRequest::class, 'request_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'attachment_id');
    }

    public function fullName(): string
    {
        return "{$this->owner}/{$this->repository}";
    }
}
