<?php

namespace App\Http\Middleware;

use App\Connector\ConnectorIdentity;
use App\Models\ConnectorAgent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Conector-0 — autentica requisições do agente por ASSINATURA Ed25519 (AGENT-V1).
 *
 * Ordem (proteção ANTES da criptografia): estrutura de headers → tamanho do body → lookup do
 * agente → janela de timestamp → verificação da assinatura → revogação → nonce (atômico, fail-closed).
 *
 * Externamente TODA falha é genérica: 401 {error:'invalid_agent_auth'}. A causa específica
 * (malformed/unknown_agent/clock_skew/bad_signature/revoked/replayed_nonce/nonce_store_unavailable)
 * fica SÓ na auditoria server-side sanitizada (nunca vaza). Nunca loga token/assinatura/chave.
 */
class VerifyAgentSignature
{
    public function __construct(private ConnectorIdentity $identity)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $agentId   = (string) $request->header('X-Agent-Id', '');
        $timestamp = $request->header('X-Timestamp', '');
        $nonce     = (string) $request->header('X-Nonce', '');
        $signature = (string) $request->header('X-Signature', '');

        // (1) Estrutura dos headers (barato, antes de qualquer cripto).
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $agentId)
            || ! preg_match('/^\d{9,11}$/', (string) $timestamp)
            || ! preg_match('#^[A-Za-z0-9+/=_-]{16,128}$#', $nonce)
            || ! preg_match('#^[A-Za-z0-9+/=]{80,100}$#', $signature)) {
            return $this->deny('malformed_headers', $agentId);
        }
        $timestamp = (int) $timestamp;

        // (2) Tamanho do body (evita usar a verificação cripto como vetor de abuso).
        $body = $request->getContent();
        if (strlen($body) > (int) config('connector.max_agent_body_bytes', 8192)) {
            return $this->deny('body_too_large', $agentId, 413);
        }

        // (3) Lookup do agente (precisa da pública para verificar).
        $agent = ConnectorAgent::where('agent_id', $agentId)->first();
        if (! $agent) {
            return $this->deny('unknown_agent', $agentId);
        }

        // (4) Janela de clock skew.
        $skew = (int) config('connector.clock_skew', 300);
        if (abs(time() - $timestamp) > $skew) {
            return $this->deny('clock_skew', $agentId);
        }

        // (5) Verificação da assinatura (sobre a string canônica AGENT-V1).
        try {
            $raw = $this->identity->decodePublicKey($agent->public_key);
        } catch (\InvalidArgumentException) {
            return $this->deny('corrupt_stored_key', $agentId);
        }
        $canonical = $this->identity->canonicalString($agentId, $request->method(), $request->getPathInfo(), $body, $timestamp, $nonce);
        if (! $this->identity->verify($raw, $signature, $canonical)) {
            return $this->deny('bad_signature', $agentId);
        }

        // (6) Revogação (só após provar a assinatura). Falha imediata.
        if ($agent->revoked_at !== null) {
            return $this->deny('revoked', $agentId);
        }

        // (7) Nonce — SET NX atômico em cache COMPARTILHADO; fail-closed se indisponível.
        try {
            $store = config('connector.nonce_store');
            $cache = $store ? Cache::store($store) : Cache::store();
            $fresh = $cache->add("connector:nonce:{$agentId}:{$nonce}", 1, (int) config('connector.nonce_ttl', 600));
        } catch (\Throwable) {
            return $this->deny('nonce_store_unavailable', $agentId); // fail closed
        }
        if (! $fresh) {
            return $this->deny('replayed_nonce', $agentId);
        }

        // Autenticado. Escopo vem do REGISTRO do agente (server-side), nunca do payload.
        $request->attributes->set('connector_agent', $agent);

        return $next($request);
    }

    private function deny(string $reason, string $agentId, int $status = 401)
    {
        Log::channel(config('logging.default'))->warning('connector.agent_auth_denied', [
            'reason'   => $reason,
            'agent_id' => $agentId !== '' ? $agentId : null,
            'ip'       => request()->ip(),
        ]);

        return response()->json(['error' => 'invalid_agent_auth'], $status === 413 ? 413 : 401);
    }
}
