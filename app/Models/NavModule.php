<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Módulo de navegação configurável (Configurador). Define rótulo/ícone/ordem e a lista
 * ordenada de "itens de menu" (keys do catálogo do FE) que aparecem nele.
 */
class NavModule extends Model
{
    protected $fillable = ['key', 'label', 'icon', 'sort_order', 'is_system', 'active', 'items', 'profiles'];

    protected $casts = [
        'items'      => 'array',
        'profiles'   => 'array',
        'is_system'  => 'boolean',
        'active'     => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Módulos ativos, na ordem definida. */
    public static function ordered()
    {
        return static::orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * Keys dos módulos que um usuário enxerga. Cada módulo pertence ao(s) perfil(is) em profiles.
     * Admin tem os SEUS módulos (profiles inclui 'admin') — menus são independentes por perfil,
     * então não há mais bypass (senão o admin veria as cópias de todos os perfis, duplicadas).
     */
    public static function keysForProfile($user): array
    {
        // aceita User (preferido) ou string de tipo (compat)
        $eff = is_string($user)
            ? [$user]
            : ($user?->effectiveProfiles() ?? []);
        if (empty($eff)) return [];
        $active = static::where('active', true)->orderBy('sort_order')->orderBy('id')->get();
        return $active->filter(fn ($m) => (bool) array_intersect($eff, (array) ($m->profiles ?? [])))
            ->pluck('key')->values()->all();
    }
}
