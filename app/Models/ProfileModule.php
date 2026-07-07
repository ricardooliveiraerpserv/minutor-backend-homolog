<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cadastro de Perfil → Módulos de navegação. Camada de NAVEGAÇÃO apenas
 * (sem relação com permissões). Por enquanto só Administrativo + Serviços.
 */
class ProfileModule extends Model
{
    protected $fillable = ['profile', 'modules'];

    protected $casts = ['modules' => 'array'];

    /** Módulos disponíveis (administrativo PRIMEIRO). */
    public const MODULES = ['administrativo', 'servicos', 'configurador'];

    /** Perfis que participam dos módulos (cliente NÃO entra — mantém portal). */
    public const PROFILES = ['admin', 'administrativo', 'coordenador', 'consultor', 'parceiro_admin'];

    /** Defaults quando não há linha cadastrada (administrativo primeiro; configurador só admin). */
    public const DEFAULTS = [
        'admin'          => ['administrativo', 'servicos', 'configurador'],
        'administrativo' => ['administrativo'],
        'coordenador'    => ['administrativo', 'servicos'],
        'consultor'      => ['servicos'],
        'parceiro_admin' => ['servicos'],
        // 'cliente' ausente de propósito → [] (sem módulos; acessa o Portal)
    ];

    /** Módulos efetivos de um perfil (linha cadastrada OU default). Não filtra por MODULES
     *  (admin pode ter módulos custom do Configurador). */
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
