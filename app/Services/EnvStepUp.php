<?php

namespace App\Services;

use App\Models\VaultUserKey;
use Illuminate\Http\Request;

/**
 * Step-up (2º fator fresco) para operações sensíveis do Cofre de Ambientes — Nível 4.
 * REUSA o mecanismo do Cofre de Senhas SEM tocá-lo: driver Microsoft consome o step-up
 * já gravado pelo popup (VaultStepUp::consume); driver TOTP verifica um código fresco
 * (Totp::verify) e grava o timestep (anti-replay). WebAuthn entra numa frente futura.
 */
class EnvStepUp
{
    /** Exige 2º fator fresco. Consome (uso único). Retorna true se satisfeito. */
    public static function satisfied(Request $request): bool
    {
        $keys = VaultUserKey::where('user_id', $request->user()->id)->first();
        if (! $keys) {
            return false;
        }

        if (VaultStepUp::driver() === 'microsoft') {
            return VaultStepUp::consume($keys); // popup MS gravou; consome
        }

        // TOTP: código fresco no payload, com anti-replay via totp_last_timestep.
        $code = (string) $request->input('totp_code');
        if (! $keys->totpConfirmed() || ! $keys->totp_secret || $code === '') {
            return false;
        }
        $timestep = Totp::verify($keys->totp_secret, $code, $keys->totp_last_timestep);
        if ($timestep === null) {
            return false;
        }
        $keys->forceFill(['totp_last_timestep' => $timestep])->save();

        return true;
    }
}
