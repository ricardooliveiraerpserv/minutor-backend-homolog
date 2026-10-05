<?php

namespace App\Connector;

/**
 * Conector — primitivas criptográficas do canal (Connector-0). Identidade ASSIMÉTRICA Ed25519:
 * o servidor guarda só a chave PÚBLICA. Protocolo de assinatura AGENT-V1 CONGELADO (ver
 * canonicalString) — Go (agente) e PHP (backend) DEVEM produzir a mesma canonicalização
 * (garantido por vetor de teste estático). Sem HMAC, sem segredo verificável no servidor.
 */
class ConnectorIdentity
{
    /** Bytes canônicos de uma chave pública Ed25519 (raw). */
    public const PUBLIC_KEY_BYTES = 32;

    /** Assinatura Ed25519 (raw). */
    public const SIGNATURE_BYTES = 64;

    /**
     * Valida e devolve os 32 bytes canônicos da chave pública Ed25519.
     * Aceita EXCLUSIVAMENTE Base64 padrão dos 32 bytes crus (não PEM, não RSA/ECDSA).
     *
     * @throws \InvalidArgumentException se malformada / tamanho / algoritmo incompatível
     */
    public function decodePublicKey(string $b64): string
    {
        $b64 = trim($b64);
        if ($b64 === '') {
            throw new \InvalidArgumentException('empty_public_key');
        }
        $raw = base64_decode($b64, true); // strict
        if ($raw === false) {
            throw new \InvalidArgumentException('invalid_base64');
        }
        if (strlen($raw) !== self::PUBLIC_KEY_BYTES) {
            throw new \InvalidArgumentException('invalid_public_key_size'); // só Ed25519 (32 bytes)
        }
        return $raw;
    }

    /** Fingerprint = sha256 dos BYTES canônicos da chave (não da string recebida). */
    public function fingerprint(string $rawPublicKey): string
    {
        return hash('sha256', $rawPublicKey);
    }

    /**
     * String canônica AGENT-V1 (CONGELADA). Campos separados por \n, UTF-8:
     *   AGENT-V1
     *   {agent_id}
     *   {METHOD upper}
     *   {path SEM querystring}
     *   {sha256(body) lowercase hex}
     *   {timestamp unix}
     *   {nonce}
     * Body vazio → sha256('') = e3b0c442... A querystring NÃO entra na assinatura (agentes não
     * põem dado sensível em query). Path normalizado com uma barra inicial.
     */
    public function canonicalString(string $agentId, string $method, string $path, string $body, int $timestamp, string $nonce): string
    {
        $method = strtoupper($method);
        $path = '/' . ltrim(parse_url($path, PHP_URL_PATH) ?: $path, '/');
        $bodyHash = hash('sha256', $body); // lowercase hex

        return implode("\n", ['AGENT-V1', $agentId, $method, $path, $bodyHash, (string) $timestamp, $nonce]);
    }

    /**
     * Verifica a assinatura Ed25519 (base64) sobre a string canônica.
     * Constante em falha (verify_detached é seguro contra timing).
     */
    public function verify(string $rawPublicKey, string $signatureB64, string $canonical): bool
    {
        $sig = base64_decode(trim($signatureB64), true);
        if ($sig === false || strlen($sig) !== self::SIGNATURE_BYTES) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($sig, $canonical, $rawPublicKey);
        } catch (\SodiumException) {
            return false;
        }
    }
}
