<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesEnvMembership;
use App\Models\EnvAccessLog;
use App\Models\EnvSecret;
use App\Services\EnvStepUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REVEAL COM ENFORCEMENT REAL (diferente do cofre de senhas, onde o blob ia na
 * listagem). Aqui o ciphertext SÓ é devolvido por este endpoint, após checar
 * membership (403 p/ não-membro) e registrar auditoria. O valor em claro só existe
 * no client após decifrar com a vaultKey — zero-knowledge preservado.
 *
 * NÍVEL 4 (Fase 2): itens `critical` exigem step-up 2º fator FRESCO (Microsoft/TOTP,
 * reusando o mecanismo do cofre) + JUSTIFICATIVA obrigatória (≥10 chars), tudo auditado.
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
            'totp_code'     => 'nullable|string|max:10',
        ]);

        $secret = EnvSecret::findOrFail($secretId);
        // Enforcement: membership + ACL fina da operação (reveal/copy) no ambiente.
        $this->requireVaultMember($request, $secret->vault_id);
        $op = ($data['action'] ?? 'reveal') === 'copy' ? 'copy' : 'reveal';
        \App\Services\EnvAccess::authorize($request->user(), $secret->environment, $op);

        // NÍVEL 4: item crítico exige step-up fresco + justificativa.
        if ($secret->critical) {
            $justification = trim((string) ($data['justification'] ?? ''));
            if (mb_strlen($justification) < 10) {
                return response()->json(['message' => 'Justificativa obrigatória (mín. 10 caracteres) para item crítico.', 'requires' => 'justification'], 422);
            }
            if (! EnvStepUp::satisfied($request)) {
                return response()->json(['message' => 'Verificação de 2º fator necessária para revelar item crítico.', 'requires' => 'stepup'], 422);
            }
        }

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
