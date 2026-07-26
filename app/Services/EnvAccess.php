<?php

namespace App\Services;

use App\Models\EnvEnvironment;
use App\Models\EnvPermission;
use App\Models\User;
use App\Models\VaultMember;

/**
 * ACL fina do Cofre de Ambientes: permissão por usuário × ambiente × operação.
 * Camada ADITIVA sobre o membership (VaultMember = a chave). Sem linha custom em
 * env_permissions → default derivado do papel de membro. Admin sempre pode tudo.
 */
class EnvAccess
{
    /**
     * Permissões EFETIVAS do usuário no ambiente: {view,reveal,copy,edit,delete,admin,source}.
     * Não-membro → tudo false.
     */
    public static function effectiveFor(User $user, EnvEnvironment $env): array
    {
        if ($user->isAdmin()) {
            return self::all('admin_global');
        }

        $member = VaultMember::where('vault_id', $env->vault_id)->where('user_id', $user->id)->first();
        if (! $member) {
            return self::none();
        }

        // Override custom por (usuário, ambiente)
        $custom = EnvPermission::where('user_id', $user->id)->where('environment_id', $env->id)->first();
        if ($custom) {
            return [
                'view'   => $custom->can_view,
                'reveal' => $custom->can_reveal,
                'copy'   => $custom->can_copy,
                'manage' => $custom->can_manage,
                'admin'  => $custom->can_admin,
                'source' => 'custom',
            ];
        }

        // Default pelo papel de membro do cliente-vault
        return match ($member->role) {
            'admin' => self::all('role_admin'),
            'write' => ['view' => true, 'reveal' => true, 'copy' => true, 'manage' => true, 'admin' => false, 'source' => 'role_write'],
            default => ['view' => true, 'reveal' => true, 'copy' => true, 'manage' => false, 'admin' => false, 'source' => 'role_read'],
        };
    }

    public static function can(User $user, EnvEnvironment $env, string $op): bool
    {
        return (bool) (self::effectiveFor($user, $env)[$op] ?? false);
    }

    /** 403 se não puder a operação. */
    public static function authorize(User $user, EnvEnvironment $env, string $op): void
    {
        abort_unless(self::can($user, $env, $op), 403, 'Sem permissão para esta operação neste ambiente.');
    }

    private static function all(string $source): array
    {
        return ['view' => true, 'reveal' => true, 'copy' => true, 'manage' => true, 'admin' => true, 'source' => $source];
    }

    private static function none(): array
    {
        return ['view' => false, 'reveal' => false, 'copy' => false, 'manage' => false, 'admin' => false, 'source' => 'none'];
    }
}
