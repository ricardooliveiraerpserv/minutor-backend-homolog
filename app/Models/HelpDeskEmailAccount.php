<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Conta de e-mail do Help Desk (recebimento). PEGADINHA: $table explícito. Senha NUNCA serializa. */
class HelpDeskEmailAccount extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    use SoftDeletes;

    protected $table = 'helpdesk_email_accounts';

    protected $fillable = [
        'name', 'email', 'brand', 'provider', 'receive_enabled', 'protocol', 'host', 'port',
        'encryption', 'username', 'password', 'inbox',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'settings',
        'default_team_id', 'enabled', 'last_status', 'last_error', 'last_checked_at',
    ];

    protected $hidden = ['password']; // nunca volta na API

    protected $casts = [
        'enabled'         => 'boolean',
        'receive_enabled' => 'boolean',
        'port'            => 'integer',
        'smtp_port'       => 'integer',
        'settings'        => 'array',
        'password'        => 'encrypted', // criptografada em repouso
        'last_checked_at' => 'datetime',
    ];

    protected $appends = ['has_password', 'connection_status'];

    public function getHasPasswordAttribute(): bool
    {
        return !empty($this->attributes['password'] ?? null);
    }

    /** Status p/ a coluna "Status de Conexão": inactive | connected | failed | untested. */
    public function getConnectionStatusAttribute(): string
    {
        if (!$this->enabled) return 'inactive';
        return $this->last_status === 'connected' ? 'connected' : ($this->last_status === 'failed' ? 'failed' : 'untested');
    }

    public function team(): BelongsTo { return $this->belongsTo(HelpDeskTeam::class, 'default_team_id'); }
}
