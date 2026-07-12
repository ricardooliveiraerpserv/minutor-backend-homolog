<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MessageAttachment extends Model
{
    protected $fillable = [
        'message_id', 'filename', 'stored_path', 'mime', 'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** URL pública pra baixar/exibir o arquivo (via disco public). */
    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->stored_path);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function isAudio(): bool
    {
        return str_starts_with($this->mime, 'audio/');
    }
}
