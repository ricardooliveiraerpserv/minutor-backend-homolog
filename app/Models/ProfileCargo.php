<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vínculo Cargo × Perfil — cargo padrão de cada perfil (users.type), registrado pelo admin.
 * Usado como cargo padrão da assinatura/perfil do usuário. Sem linha = DEFAULTS.
 */
class ProfileCargo extends Model
{
    protected $fillable = ['profile', 'cargo'];

    /** Perfis que podem ter cargo (inclui cliente). */
    public const PROFILES = ['admin', 'administrativo', 'coordenador', 'consultor', 'parceiro_admin', 'cliente'];

    /** Defaults quando não há linha cadastrada. Regra: fora de admin/coord/financeiro/cliente → Consultor. */
    public const DEFAULTS = [
        'admin'          => 'Administrador',
        'administrativo' => 'Financeiro',
        'coordenador'    => 'Coordenador',
        'consultor'      => 'Consultor',
        'parceiro_admin' => 'Consultor',
        'cliente'        => 'Cliente',
    ];

    /** Cargo efetivo de um perfil (linha cadastrada OU default). */
    public static function forProfile(?string $profile): string
    {
        if (!$profile) {
            return 'Consultor';
        }
        $row = static::where('profile', $profile)->first();
        if ($row && trim((string) $row->cargo) !== '') {
            return $row->cargo;
        }
        return self::DEFAULTS[$profile] ?? 'Consultor';
    }
}
