<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserHourlyRateLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'changed_by',
        'old_hourly_rate',
        'new_hourly_rate',
        'old_rate_type',
        'new_rate_type',
        'reason',
        'old_consultant_type',
        'new_consultant_type',
    ];

    /**
     * Retorna os valores (hourly_rate, rate_type, consultant_type) em vigor no 1º dia do mês informado.
     * Regra: mudanças feitas DURANTE o mês só valem a partir do mês seguinte.
     */
    public static function effectiveValuesAt(int $userId, \App\Models\User $user, string $firstDayOfMonth): array
    {
        $log = static::where('user_id', $userId)
            ->whereDate('created_at', '<', $firstDayOfMonth)
            ->orderBy('created_at', 'desc')
            ->first();

        return [
            'hourly_rate'     => $log?->new_hourly_rate    ?? $user->hourly_rate,
            'rate_type'       => $log?->new_rate_type       ?? $user->rate_type,
            'consultant_type' => $log?->new_consultant_type ?? $user->consultant_type,
        ];
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_hourly_rate' => 'decimal:2',
            'new_hourly_rate' => 'decimal:2',
        ];
    }

    /**
     * Relacionamento com o usuário que teve o valor alterado
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relacionamento com o usuário que fez a alteração
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Scope para filtrar por usuário
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope para ordenar por data mais recente
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
