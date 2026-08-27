<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Respondente unificado da matriz — UMA matriz, TRÊS origens (internal/partner/
 * candidate). Mantém `users` limpo (candidatos externos não viram usuários).
 */
class SkillRespondent extends Model
{
    public const TYPE_INTERNAL = 'internal';
    public const TYPE_PARTNER = 'partner';
    public const TYPE_CANDIDATE = 'candidate';

    public const TYPES = [self::TYPE_INTERNAL, self::TYPE_PARTNER, self::TYPE_CANDIDATE];

    /** Classificação de negócio (editável). Black List = candidatos problemáticos. */
    public const CLASSIFICATIONS = [
        'pre_candidato' => 'Pré-candidato',
        'candidato' => 'Candidato',
        'erpserv' => 'Interno',      // rótulo "Interno" (valor 'erpserv' mantido p/ não quebrar a lógica de contratação)
        'freelance' => 'Freelance',
        'parceiro' => 'Parceiro',
        'blacklist' => 'Black List',
    ];

    protected $fillable = [
        'type', 'classification', 'user_id', 'partner_id', 'name', 'email', 'phone', 'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(SkillSubmission::class, 'respondent_id');
    }
}
