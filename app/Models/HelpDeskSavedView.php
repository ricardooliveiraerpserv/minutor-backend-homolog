<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visualização salva da fila do Help Desk: um conjunto de filtros nomeado.
 * Pessoal (do dono) ou compartilhada (visível a todos os agentes).
 */
class HelpDeskSavedView extends Model
{
    protected $table = 'helpdesk_saved_views';

    protected $fillable = ['user_id', 'name', 'filters', 'is_shared'];

    protected $casts = [
        'filters'   => 'array',
        'is_shared' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
