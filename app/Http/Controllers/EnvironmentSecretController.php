<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesEnvMembership;
use App\Models\EnvAccessLog;
use App\Models\EnvSecret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REVEAL COM ENFORCEMENT REAL (diferente do cofre de senhas, onde o blob ia na
 * listagem). Aqui o ciphertext SÓ é devolvido por este endpoint, após checar
 * membership (403 p/ não-membro) e registrar auditoria. O valor em claro só existe
 * no client após decifrar com a vaultKey — zero-knowledge preservado.
 *
 * NOTA F1a: gate por membership + auditoria. Step-up por-operação e justificativa
 * obrigatória em itens `critical` entram na Fase 2 (EnvStepUp).
 */
class EnvironmentSecretController extends Controller
{
    use ResolvesEnvMembership;

    public function reveal(Request $request, int $secretId): JsonResponse
    {
        $this->guardInternal($request);
        $data = $request->validate([
            'action'        => 'sometimes|in:reveal,copy',
            'justification' => 'nullable|string|max:1000',
        ]);

        $secret = EnvSecret::findOrFail($secretId);
        // Enforcement: precisa ser membro do cliente-vault deste segredo
        $this->requireVaultMember($request, $secret->vault_id);

        $action = ($data['action'] ?? 'reveal') === 'copy' ? 'secret_copy' : 'secret_reveal';
        EnvAccessLog::record(
            $request,
            $action,
            ['environment_id' => $secret->environment_id, 'secret_id' => $secret->id],
            $data['justification'] ?? null
        );

        // NUNCA logar o valor. Devolve o ciphertext; o client decifra com getVaultKey.
        return response()->json([
            'data'        => $secret->getAttributes()['data'],
            'key_version' => $secret->key_version,
            'vault_id'    => $secret->vault_id,
        ]);
    }
}
