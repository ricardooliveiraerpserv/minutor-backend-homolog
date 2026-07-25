<?php

namespace App\Services;

use App\Models\VaultUserKey;
use Illuminate\Support\Facades\Hash;

/**
 * Cofre — 2º fator via Microsoft Entra (step-up).
 *
 * Fluxo: FE abre popup no authorize (prompt=login → força re-auth + MFA corporativa);
 * o callback OAuth troca o code, valida tenant + conta (oid pinado no 1º uso) e emite
 * um TOKEN efêmero (5 min) que o FE envia no unlock/operações destrutivas.
 * A Microsoft prova só IDENTIDADE — a master password continua sendo a única chave
 * dos dados (zero-knowledge intacto).
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
     * Processa o retorno do OAuth (já com os tokens trocados): valida tenant e conta,
     * pina o oid no primeiro uso e emite o token de step-up.
     * Retorna o token ou null (conta errada/tenant errado).
     */
    public static function completeFromTokens(int $userId, array $tokens): ?string
    {
        $claims = self::idTokenClaims((string) ($tokens['id_token'] ?? ''));
        if (! $claims) {
            return null;
        }

        // Tenant: quando configurado (não-'common'), tem que bater
        $tenant = (string) config('services.microsoft_calendar.tenant_id');
        if ($tenant && $tenant !== 'common' && ($claims['tid'] ?? '') !== $tenant) {
            return null;
        }

        $oid = (string) ($claims['oid'] ?? '');
        if ($oid === '') {
            return null;
        }

        $keys = VaultUserKey::firstOrCreate(['user_id' => $userId]);
        if (empty($keys->ms_oid)) {
            $keys->ms_oid = $oid; // 1º step-up pina a conta Entra deste usuário
        } elseif ($keys->ms_oid !== $oid) {
            return null; // logou com OUTRA conta Microsoft — recusa
        }

        $token = base64_encode(random_bytes(32));
        $keys->forceFill([
            'stepup_token_hash'       => Hash::make($token),
            'stepup_token_expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
        $keys->save();

        return $token;
    }

    /** Valida um step-up token dentro da janela (NÃO consome: 5 min cobrem lote de operações). */
    public static function check(?VaultUserKey $keys, ?string $token): bool
    {
        if (! $keys || ! $token || ! $keys->stepup_token_hash) {
            return false;
        }

        return $keys->stepup_token_expires_at?->isFuture()
            && Hash::check($token, $keys->stepup_token_hash);
    }

    /**
     * Claims do id_token SEM verificar assinatura — seguro AQUI porque o token veio
     * direto do endpoint de token da Microsoft via TLS (confidential client), não do browser.
     */
    private static function idTokenClaims(string $idToken): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($payload) ? $payload : null;
    }
}
