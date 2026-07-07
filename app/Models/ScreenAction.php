<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de ações disponíveis por tela (rotina). Define QUAIS ações existem;
 * as permissões por ação vivem em nav_screens.abilities.
 */
class ScreenAction extends Model
{
    protected $fillable = ['screen_key', 'action_key', 'label', 'description', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    /** Ações agrupadas por screen_key (para o FE carregar dinamicamente). */
    public static function catalog(): array
    {
        return static::orderBy('screen_key')->orderBy('sort_order')->get()
            ->groupBy('screen_key')
            ->map(fn ($rows) => $rows->map(fn ($r) => [
                'action_key'  => $r->action_key,
                'label'       => $r->label,
                'description' => $r->description,
            ])->values())
            ->toArray();
    }
}
