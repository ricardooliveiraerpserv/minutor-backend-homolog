<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Conector — identidade de um agente on-prem (1 por ambiente). Guarda SÓ a chave PÚBLICA
 * (Ed25519) — nenhum segredo verificável em repouso. O escopo (customer/environment) é
 * autoridade server-side; nunca vem do payload do agente.
 */
class ConnectorAgent extends Model
{
    protected $fillable = [
        'agent_id', 'customer_id', 'environment_id', 'public_key', 'public_key_fingerprint',
        'agent_version', 'enrolled_at', 'revoked_at', 'created_by',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'revoked_at'  => 'datetime',
    ];

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
