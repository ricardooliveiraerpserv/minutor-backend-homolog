<?php

namespace App\Services;

use App\Models\EnvEnvironment;
use App\Models\EnvGroupPermission;
use App\Models\EnvPermission;
use App\Models\User;
use App\Models\VaultMember;
use Illuminate\Support\Facades\DB;

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

        // 1) Override custom por (usuário, ambiente) — precedência máxima
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

        // 2) Herança de GRUPO: união (OR) das permissões dos grupos do usuário neste ambiente.
        //    Herança automática — inclui quem entrar no grupo depois (resolvido em tempo de leitura).
        $groupIds = DB::table('consultant_group_user')->where('user_id', $user->id)->pluck('consultant_group_id');
        if ($groupIds->isNotEmpty()) {
            $gp = EnvGroupPermission::where('environment_id', $env->id)->whereIn('consultant_group_id', $groupIds)->get();
            if ($gp->isNotEmpty()) {
                return [
                    'view'   => (bool) $gp->contains(fn ($g) => $g->can_view),
                    'reveal' => (bool) $gp->contains(fn ($g) => $g->can_reveal),
                    'copy'   => (bool) $gp->contains(fn ($g) => $g->can_copy),
                    'manage' => (bool) $gp->contains(fn ($g) => $g->can_manage),
                    'admin'  => (bool) $gp->contains(fn ($g) => $g->can_admin),
                    'source' => 'group',
                ];
            }
        }

        // 3) Default pelo papel de membro do cliente-vault
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
