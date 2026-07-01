<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cadastro de Perfil → Módulos de navegação (Serviços / Administrativo).
 * Camada de NAVEGAÇÃO apenas — não tem relação com permissões/PermissionService.
 */
class ProfileModule extends Model
{
    protected $fillable = ['profile', 'modules'];

    protected $casts = ['modules' => 'array'];

    /** Módulos disponíveis. */
    public const MODULES = ['servicos', 'administrativo', 'crm'];

    /** Perfis que participam dos módulos (cliente NÃO entra — mantém portal). */
    public const PROFILES = ['admin', 'administrativo', 'coordenador', 'consultor', 'parceiro_admin'];

    /** Defaults quando não há linha cadastrada para o perfil. */
    public const DEFAULTS = [
        'admin'          => ['servicos', 'administrativo', 'crm'],
        'administrativo' => ['administrativo', 'crm'],
        'coordenador'    => ['servicos', 'administrativo'],
        'consultor'      => ['servicos'],
        'parceiro_admin' => ['servicos'],
        // 'cliente' ausente de propósito → [] (sem módulos)
    ];

    /** Módulos efetivos de um perfil (linha cadastrada OU default). */
    public static function forProfile(?string $profile): array
    {
        if (!$profile) {
            return [];
        }
        $row = static::where('profile', $profile)->first();
        if ($row) {
            return array_values(array_intersect(self::MODULES, (array) $row->modules));
        }
        return self::DEFAULTS[$profile] ?? [];
    }
}
