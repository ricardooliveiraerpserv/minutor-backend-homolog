<?php

namespace App\Http\Controllers;

use App\Connector\ConnectorIdentity;
use App\Models\ConnectorAgent;
use App\Models\ConnectorEnrollmentToken;
use App\Models\EnvEnvironment;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conector-0 — identidade e canal seguro. SÓ enrollment/identidade/assinatura/revogação.
 * NÃO há heartbeat/estado/comando aqui (Connector-1+). O escopo (customer/environment) é sempre
 * autoridade server-side (do token ou do registro do agente), nunca do payload.
 */
class ConnectorAgentController extends Controller
{
    public function __construct(private ConnectorIdentity $identity, private SourceDocCustomerScope $scope)
    {
    }

    // ── Agente (bootstrap por TOKEN, não por sessão) ──────────────────────────

    /**
     * POST /connector/enroll {enrollment_token, public_key, agent_version?}
     * Consumo ATÔMICO: valida token (lockForUpdate) → cria agente → consome token, tudo em transação.
     * Duas requisições com o mesmo token ⇒ exatamente 1 agente / 1 consumo; a 2ª rejeita.
     * Dois tokens para o mesmo ambiente ⇒ só um produz identidade ativa (unique parcial → 409).
     */
    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_token' => 'required|string|max:200',
            'public_key'       => 'required|string|max:200',
            'agent_version'    => 'nullable|string|max:40',
        ]);

        // Chave pública Ed25519 canônica (32 bytes; rejeita PEM/RSA/ECDSA/malformada).
        try {
            $raw = $this->identity->decodePublicKey($data['public_key']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid_public_key'], 422);
        }
        $fingerprint = $this->identity->fingerprint($raw);
        $tokenHash = hash('sha256', trim($data['enrollment_token']));

        try {
            $agent = DB::transaction(function () use ($tokenHash, $data, $raw, $fingerprint) {
                $token = ConnectorEnrollmentToken::where('token_hash', $tokenHash)->lockForUpdate()->first();
                if (! $token || $token->consumed_at !== null || $token->expires_at->isPast()) {
                    throw new \DomainException('invalid_or_used_token');
                }
                $agentId = (string) Str::uuid();
                $agent = ConnectorAgent::create([
                    'agent_id'               => $agentId,
                    'customer_id'            => $token->customer_id,     // escopo do TOKEN (server-side)
                    'environment_id'         => $token->environment_id,  // escopo do TOKEN (server-side)
                    'public_key'             => base64_encode($raw),      // Base64 canônico dos 32 bytes
                    'public_key_fingerprint' => $fingerprint,
                    'agent_version'          => $data['agent_version'] ?? null,
                    'enrolled_at'            => now(),
                ]);
                $token->update(['consumed_at' => now(), 'consumed_by_agent_id' => $agentId]);

                return $agent;
            });
        } catch (\DomainException) {
            return response()->json(['error' => 'invalid_or_used_token'], 401);
        } catch (UniqueConstraintViolationException) {
            // Ambiente já tem agente ativo (unique parcial) — re-enroll exige revogar o anterior.
            return response()->json(['error' => 'environment_already_enrolled'], 409);
        }

        return response()->json([
            'agent_id'             => $agent->agent_id,
            'server_time'          => now()->timestamp, // calibra o clock do agente (soft-sync)
            'heartbeat_interval_s' => 60,               // informativo; heartbeat só no Connector-1
        ], 201);
    }

    /** GET /connector/whoami — endpoint MÍNIMO de prova do canal (assinado). Sem heartbeat/estado. */
    public function whoami(Request $request): JsonResponse
    {
        /** @var ConnectorAgent $agent */
        $agent = $request->attributes->get('connector_agent');

        return response()->json(['data' => [
            'agent_id'       => $agent->agent_id,
            'customer_id'    => (int) $agent->customer_id,
            'environment_id' => (int) $agent->environment_id,
            'fingerprint'    => $agent->public_key_fingerprint,
        ]]);
    }

    // ── Admin (sessão + prosight.operations.manage) ───────────────────────────

    /** POST /prosight/environments/{environmentId}/connector/enrollment-token */
    public function issueToken(Request $request, int $environmentId): JsonResponse
    {
        $env = EnvEnvironment::query()->whereKey($environmentId)->first(['id', 'customer_id']);
        // Escopo: ambiente inexistente ou fora do escopo do usuário → 404 (não revela).
        if (! $env || ! $this->scope->canAccessCustomerId($request->user(), (int) $env->customer_id)) {
            return response()->json(['message' => 'Ambiente não encontrado.'], 404);
        }

        $token = bin2hex(random_bytes(32)); // 256 bits
        $ttl = (int) config('connector.enrollment_ttl', 900);
        ConnectorEnrollmentToken::create([
            'token_hash'     => hash('sha256', $token),
            'customer_id'    => (int) $env->customer_id, // consistência garantida: derivado do AMBIENTE
            'environment_id' => (int) $env->id,
            'expires_at'     => now()->addSeconds($ttl),
            'created_by'     => $request->user()?->id,
        ]);

        // Token exibido UMA vez; nunca logado; guardado só como hash.
        return response()->json(['data' => [
            'enrollment_token' => $token,
            'environment_id'   => (int) $env->id,
            'customer_id'      => (int) $env->customer_id,
            'expires_at'       => now()->addSeconds($ttl)->toIso8601String(),
        ]], 201);
    }

    /** GET /prosight/environments/{environmentId}/connector/agent — status da IDENTIDADE (não heartbeat). */
    public function agentStatus(Request $request, int $environmentId): JsonResponse
    {
        $env = EnvEnvironment::query()->whereKey($environmentId)->first(['id', 'customer_id']);
        if (! $env || ! $this->scope->canAccessCustomerId($request->user(), (int) $env->customer_id)) {
            return response()->json(['message' => 'Ambiente não encontrado.'], 404);
        }

        $agent = ConnectorAgent::where('environment_id', $env->id)->whereNull('revoked_at')
            ->orderByDesc('enrolled_at')->first();

        return response()->json(['data' => $agent ? [
            'agent_id'      => $agent->agent_id,
            'fingerprint'   => $agent->public_key_fingerprint,
            'agent_version' => $agent->agent_version,
            'enrolled_at'   => $agent->enrolled_at?->toIso8601String(),
            'revoked_at'    => $agent->revoked_at?->toIso8601String(),
        ] : null]);
    }

    /** DELETE /prosight/connector/agents/{agentId} — revoga (não apaga identidade/auditoria). */
    public function revoke(Request $request, string $agentId): JsonResponse
    {
        $agent = ConnectorAgent::where('agent_id', $agentId)->first();
        if (! $agent || ! $this->scope->canAccessCustomerId($request->user(), (int) $agent->customer_id)) {
            return response()->json(['message' => 'Agente não encontrado.'], 404);
        }
        if ($agent->revoked_at === null) {
            $agent->update(['revoked_at' => now()]);
        }

        return response()->json(['data' => ['agent_id' => $agent->agent_id, 'revoked_at' => $agent->revoked_at?->toIso8601String()]]);
    }
}
