<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CRM — combinação de filtros salva por usuário/tela (conceito RD "Filtros salvos"). */
class CrmSavedFilter extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $fillable = ['user_id', 'scope', 'name', 'payload'];

    protected $casts = ['payload' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
