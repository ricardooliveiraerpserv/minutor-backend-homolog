<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Módulo de navegação configurável (Configurador). Define rótulo/ícone/ordem e a lista
 * ordenada de "itens de menu" (keys do catálogo do FE) que aparecem nele.
 */
class NavModule extends Model
{
    protected $fillable = ['key', 'label', 'icon', 'sort_order', 'is_system', 'active', 'items'];

    protected $casts = [
        'items'      => 'array',
        'is_system'  => 'boolean',
        'active'     => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Módulos ativos, na ordem definida. */
    public static function ordered()
    {
        return static::orderBy('sort_order')->orderBy('id')->get();
    }
}
