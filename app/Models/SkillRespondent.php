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

    /**
     * Classificação + valor CONFORME O CADASTRO DE USUÁRIO (pedido do Ricardo 27/08):
     * quando o respondente já é um usuário, a classificação e o valor vêm do cadastro,
     * não do manual/auto-declarado.
     *   parceiro_admin → Parceiro ; work_bond=freelance → Freelance ; senão → Interno.
     *   valor = valor-hora efetivo do usuário (parceiro fixed herda do parceiro).
     * Espera o $user já com `partner` carregado (p/ o effective_hourly_rate). null = sem usuário.
     *
     * @return array{classification:string,label:?string,valor:?string}|null
     */
    public static function classificationFromUser(?User $user): ?array
    {
        if (! $user) return null;

        if ($user->type === 'parceiro_admin')     $c = 'parceiro';
        elseif ($user->work_bond === 'freelance') $c = 'freelance';
        else                                       $c = 'erpserv'; // Interno

        $rate = $user->effective_hourly_rate; // string decimal | null
        $valor = ($rate !== null && $rate !== '')
            ? 'R$ ' . number_format((float) $rate, 2, ',', '.')
            : null;

        return [
            'classification' => $c,
            'label' => self::CLASSIFICATIONS[$c] ?? null,
            'valor' => $valor,
        ];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(SkillSubmission::class, 'respondent_id');
    }
}
