<?php

namespace App\Http\Controllers\Concerns;

use App\Models\EnvEnvironment;
use App\Models\EnvSecret;
use App\Models\User;
use App\Models\VaultMember;
use Illuminate\Http\Request;

/**
 * Membership do Cofre de Ambientes: acesso a um ambiente = ser membro do
 * cliente-vault (reusa VaultMember, sem tocar na segurança existente).
 */
trait ResolvesEnvMembership
{
    private const ENV_INTERNAL_TYPES = ['admin', 'administrativo', 'coordenador', 'consultor'];

    protected function guardInternal(Request $request): User
    {
        $user = $request->user();
        abort_unless(in_array($user->effectiveType(), self::ENV_INTERNAL_TYPES, true), 403);

        return $user;
    }

    /** Exige membership no vault; needRole: null|write|admin. */
    protected function requireVaultMember(Request $request, int $vaultId, ?string $needRole = null): VaultMember
    {
        $member = VaultMember::where('vault_id', $vaultId)->where('user_id', $request->user()->id)->first();
        abort_if(! $member, 404);
        if ($needRole === 'admin') {
            abort_unless($member->role === 'admin', 403);
        } elseif ($needRole === 'write') {
            abort_unless(in_array($member->role, ['admin', 'write'], true), 403);
        }

        return $member;
    }

    /** Resolve um ambiente garantindo membership no cliente-vault dele. */
    protected function envWithMembership(Request $request, int $envId, ?string $needRole = null): EnvEnvironment
    {
        $env = EnvEnvironment::findOrFail($envId);
        $this->requireVaultMember($request, $env->vault_id, $needRole);

        return $env;
    }

    /**
     * Resolve o ambiente exigindo membership (404) + a OPERAÇÃO na ACL fina (403).
     * op: view|reveal|copy|edit|delete|admin. Substitui a checagem só-por-papel.
     */
    protected function envAuthorized(Request $request, int $envId, string $op): EnvEnvironment
    {
        $env = EnvEnvironment::findOrFail($envId);
        $this->requireVaultMember($request, $env->vault_id);
        \App\Services\EnvAccess::authorize($request->user(), $env, $op);

        return $env;
    }

    /**
     * Cria/atualiza/mantém o segredo (blob cifrado no client) de um recurso.
     * $blob null/'' → mantém o secret atual. Retorna o secret_id resultante.
     */
    protected function syncSecret(Request $request, EnvEnvironment $env, ?int $currentSecretId, ?string $blob, string $kind, bool $critical): ?int
    {
        if (empty($blob)) {
            return $currentSecretId;
        }
        if ($currentSecretId) {
            EnvSecret::where('id', $currentSecretId)->update([
                'data'        => $blob,
                'key_version' => (int) $request->input('key_version', 1),
                'critical'    => $critical,
                'updated_by'  => $request->user()->id,
            ]);

            return $currentSecretId;
        }

        return EnvSecret::create([
            'environment_id' => $env->id,
            'vault_id'       => $env->vault_id,
            'kind'           => $kind,
            'data'           => $blob,
            'key_version'    => (int) $request->input('key_version', 1),
            'critical'       => $critical,
            'created_by'     => $request->user()->id,
            'updated_by'     => $request->user()->id,
        ])->id;
    }
}
