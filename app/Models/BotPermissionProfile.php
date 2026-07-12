<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Política padrão de permissões do BOT por perfil de user
 * (admin, coordenador, consultor, etc).
 *
 * Aplicada na criação do user (UserController) e exibida na aba
 * "Permissões padrão" de /configuracoes/bot-minutor.
 */
class BotPermissionProfile extends Model
{
    protected $fillable = [
        'profile_type', 'label', 'description', 'can_use_bot',
        'allowed_scopes', 'visibility', 'scope_overrides',
    ];

    protected $casts = [
        'can_use_bot'     => 'boolean',
        'allowed_scopes'  => 'array',
        'scope_overrides' => 'array',
    ];

    public static function forType(string $type): ?self
    {
        return self::query()->where('profile_type', $type)->first();
    }
}
