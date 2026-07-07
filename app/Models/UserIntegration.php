<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Integração externa de um usuário (ex.: Microsoft 365 / Outlook). Tokens criptografados. */
class UserIntegration extends Model
{
    protected $table = 'user_integrations';

    protected $fillable = [
        'user_id', 'provider', 'access_token', 'refresh_token', 'account_email',
        'expires_at', 'connected_at', 'last_sync_at', 'cached_events',
    ];

    // Tokens NUNCA voltam na API.
    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token'  => 'encrypted',
        'refresh_token' => 'encrypted',
        'cached_events' => 'array',
        'expires_at'    => 'datetime',
        'connected_at'  => 'datetime',
        'last_sync_at'  => 'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** access_token expirado (ou prestes a)? */
    public function isExpired(): bool
    {
        return !$this->expires_at || $this->expires_at->subMinutes(2)->isPast();
    }
}
