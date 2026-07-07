<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tela de navegação (Configurador — modelo árvore). Entidade ÚNICA por `key` (= href).
 * Guarda as PERMISSÕES (profiles + users) e o estado ativo/inativo. A estrutura do menu
 * (nav_modules.items) apenas REFERENCIA telas por `key`; alterar aqui impacta todas as
 * ocorrências na árvore.
 */
class NavScreen extends Model
{
    protected $fillable = ['key', 'label', 'route', 'active', 'profiles', 'users', 'abilities'];

    protected $casts = [
        'active'    => 'boolean',
        'profiles'  => 'array',
        'users'     => 'array',
        'abilities' => 'array',   // { action: { profiles[], users[], deny_users[] } }
    ];
}
