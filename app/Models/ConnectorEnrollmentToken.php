<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Conector — token de bootstrap do enrollment: uso ÚNICO, curta validade, atado a
 * customer+environment. Guarda só o sha256 do token (nunca o token em claro). O token
 * NUNCA vira credencial permanente — só autoriza UM enroll (que produz a identidade Ed25519).
 */
class ConnectorEnrollmentToken extends Model
{
    protected $fillable = [
        'token_hash', 'customer_id', 'environment_id', 'expires_at', 'consumed_at', 'consumed_by_agent_id', 'created_by',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
