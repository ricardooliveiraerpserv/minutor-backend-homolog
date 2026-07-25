<?php

namespace App\Services;

use App\Models\VaultUserKey;

/**
 * Cofre — 2º fator via Microsoft Entra (step-up), desenho robusto por POLLING.
 *
 * Fluxo: FE abre popup no authorize (prompt=login → re-auth + MFA corporativa);
 * o callback OAuth troca o code, identifica a conta via Graph /me (NÃO depende de
 * parsear id_token) e grava um step-up com validade curta (5 min) no servidor.
 * O FE então faz POLL em /vault/ms/status até ficar ativo (não depende de
 * postMessage nem de o popup fechar — COOP-proof). As operações consomem o step-up.
 *
 * Tenant é garantido pelo endpoint OAuth ser específico do tenant (authorizeUrl usa
 * /{tenant}/oauth2/...), então só contas do tenant conseguem autenticar. A conta é
 * pinada (ms_oid) no 1º uso; login com outra conta é recusado. A Microsoft prova só
 * IDENTIDADE — a master password segue a única chave (zero-knowledge intacto).
 */
class VaultStepUp
{
    public const TTL_MINUTES = 5;

    /** Driver do 2º fator: env VAULT_2FA_DRIVER ou auto (microsoft se Entra configurado). */
    public static function driver(): string
    {
        $env = config('services.vault.second_factor');
        if (in_array($env, ['microsoft', 'totp'], true)) {
            return $env;
        }

        return MicrosoftCalendarService::configured() ? 'microsoft' : 'totp';
    }

    /**
     * Processa o retorno do OAuth: identifica a conta via Graph /me, pina no 1º uso
     * e grava o step-up (timestamp). Retorna true em sucesso.
     */
    public static function completeFromTokens(int $userId, array $tokens): bool
    {
        $accessToken = (string) ($tokens['access_token'] ?? '');
        if ($accessToken === '') {
            return false;
        }
        $account = MicrosoftCalendarService::me($accessToken); // email/UPN da conta
        if (! $account) {
            return false;
        }
        $account = mb_strtolower(trim($account));

        $keys = VaultUserKey::firstOrCreate(['user_id' => $userId]);
        if (empty($keys->ms_oid)) {
            $keys->ms_oid = $account; // 1º step-up pina a conta Microsoft
        } elseif (mb_strtolower(trim($keys->ms_oid)) !== $account) {
            return false; // logou com OUTRA conta Microsoft — recusa
        }
        $keys->forceFill(['stepup_token_expires_at' => now()->addMinutes(self::TTL_MINUTES)])->save();

        return true;
    }

    /** Existe um step-up válido (não expirado) p/ este usuário? NÃO consome. */
    public static function active(?VaultUserKey $keys): bool
    {
        return (bool) ($keys?->stepup_token_expires_at?->isFuture());
    }

    /** Valida E CONSOME o step-up (uso único por operação). */
    public static function consume(?VaultUserKey $keys): bool
    {
        if (! self::active($keys)) {
            return false;
        }
        $keys->forceFill(['stepup_token_expires_at' => null])->save();

        return true;
    }
}
