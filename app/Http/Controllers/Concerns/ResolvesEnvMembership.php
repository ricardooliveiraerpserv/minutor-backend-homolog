<?php

namespace App\Http\Controllers\Concerns;

use App\Models\EnvEnvironment;
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
}
