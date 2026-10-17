<?php

namespace App\Http\Controllers;

use App\Models\VaultAccessLog;
use App\Models\VaultOrgRecovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Escrow corporativo (Caminho A): a chave de recuperação da EMPRESA.
 * - Pública: qualquer usuário interno lê (precisa pra cifrar o vaultKey ao criar pasta).
 * - Privada (cifrada): só admin do cofre lê, pra recuperar pastas.
 * Zero-knowledge: o servidor nunca recebe a Chave de Recuperação da Empresa em claro;
 * só guarda a privada JÁ cifrada por ela.
 */
class VaultOrgRecoveryController extends Controller
{
    private function internal(Request $request)
    {
        $user = $request->user();
        abort_if($user === null || $user->customer_id !== null, 403);
        return $user;
    }

    private function admin(Request $request)
    {
        $user = $this->internal($request);
        abort_unless($user->hasAccess('environments.admin'), 403);
        return $user;
    }

    /** Status + chave pública da empresa (pra cifrar o escrow ao criar pasta). */
    public function status(Request $request): JsonResponse
    {
        $this->internal($request);
        $org = VaultOrgRecovery::current();

        return response()->json([
            'configured' => $org !== null,
            'public_key' => $org?->public_key,
        ]);
    }

    /**
     * Configura a chave da empresa. Admin do cofre.
     * Com replace=true, SUBSTITUI a atual (rotação / chave perdida antes de guardar).
     * Aviso: pastas já cifradas com a chave ANTIGA deixam de ser recuperáveis pelo escrow
     * até o backfill com a nova — o FE avisa.
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $this->admin($request);

        $data = $request->validate([
            'public_key'            => 'required|string|max:4000',
            'encrypted_private_key' => 'required|string|max:8000',
            'replace'               => 'sometimes|boolean',
        ]);

        if (VaultOrgRecovery::current() !== null) {
            if (! ($data['replace'] ?? false)) {
                return response()->json(['message' => 'A chave de recuperação da empresa já está configurada.'], 409);
            }
            VaultOrgRecovery::query()->delete();
        }

        $org = VaultOrgRecovery::create([
            'public_key'            => $data['public_key'],
            'encrypted_private_key' => $data['encrypted_private_key'],
            'created_by'            => $user->id,
        ]);

        VaultAccessLog::record($request, 'org_recovery_setup');

        return response()->json(['configured' => true, 'public_key' => $org->public_key], 201);
    }

    /** Blob da privada da empresa (cifrada) — admin cola a Chave de Recuperação pra decifrar no navegador. */
    public function privateBlob(Request $request): JsonResponse
    {
        $this->admin($request);
        $org = VaultOrgRecovery::current();
        abort_if($org === null, 404);

        VaultAccessLog::record($request, 'org_recovery_access');

        return response()->json([
            'public_key'            => $org->public_key,
            'encrypted_private_key' => $org->encrypted_private_key,
        ]);
    }
}
