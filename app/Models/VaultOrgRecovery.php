<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Chave de recuperação da EMPRESA (escrow). Linha única.
 * public_key: RSA pública (SPKI base64). encrypted_private_key: RSA privada cifrada
 * com a Chave de Recuperação da Empresa (que só os admins guardam — nunca no servidor em claro).
 */
class VaultOrgRecovery extends Model
{
    protected $table = 'vault_org_recovery';

    protected $fillable = ['public_key', 'encrypted_private_key', 'created_by'];

    protected $hidden = ['encrypted_private_key'];

    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }
}
