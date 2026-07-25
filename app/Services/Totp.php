<?php

namespace App\Services;

/**
 * TOTP (RFC 6238) em PHP puro — 2FA do Cofre de Senhas.
 * Sem dependência composer de propósito: deploys cirúrgicos não levam vendor/.
 * SHA1 / 6 dígitos / período 30s (default de Google Authenticator e afins).
 */
class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secret novo: 20 bytes aleatórios em base32 (padrão dos autenticadores). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Verifica um código na janela atual ±$window timesteps.
     * Retorna o TIMESTEP aceito (p/ anti-replay: gravar e rejeitar <= último) ou null.
     */
    public static function verify(string $secret, string $code, ?int $lastAcceptedTimestep = null, int $window = 1): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $current = intdiv(time(), self::PERIOD);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $timestep = $current + $offset;
            if ($lastAcceptedTimestep !== null && $timestep <= $lastAcceptedTimestep) {
                continue; // anti-replay: código de timestep já usado
            }
            if (hash_equals(self::code($secret, $timestep), $code)) {
                return $timestep;
            }
        }

        return null;
    }

    /** Código de 6 dígitos para um timestep (dynamic truncation do RFC 4226). */
    public static function code(string $secret, int $timestep): string
    {
        $key = self::base32Decode($secret);
        $counter = pack('J', $timestep); // 64-bit big-endian
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            (ord($hash[$offset + 1]) << 16) |
            (ord($hash[$offset + 2]) << 8) |
            ord($hash[$offset + 3])
        ) % (10 ** self::DIGITS);

        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** URI de provisionamento (QR renderizado no client). */
    public static function otpauthUri(string $secret, string $accountEmail): string
    {
        return 'otpauth://totp/' . rawurlencode('Minutor:' . $accountEmail)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode('Minutor')
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    private static function base32Encode(string $bytes): string
    {
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $out .= self::ALPHABET[($value >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($value << (5 - $bits)) & 31];
        }

        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(str_replace([' ', '='], '', $b32));
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($b32) as $char) {
            $idx = strpos(self::ALPHABET, $char);
            if ($idx === false) {
                continue;
            }
            $value = ($value << 5) | $idx;
            $bits += 5;
            if ($bits >= 8) {
                $out .= chr(($value >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }

        return $out;
    }
}
