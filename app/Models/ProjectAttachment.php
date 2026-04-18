<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectAttachment extends Model
{
    protected $fillable = [
        'project_id', 'contract_attachment_id',
        'uploaded_by_id', 'type', 'path', 'original_name', 'mime_type', 'size',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contractAttachment(): BelongsTo
    {
        return $this->belongsTo(ContractAttachment::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    // Resolve display name and download path regardless of source
    public function getDisplayNameAttribute(): string
    {
        if ($this->original_name) return $this->original_name;
        return $this->contractAttachment?->original_name ?? 'Arquivo';
    }

    public function getEffectivePathAttribute(): ?string
    {
        return $this->path ?? $this->contractAttachment?->path;
    }
}
