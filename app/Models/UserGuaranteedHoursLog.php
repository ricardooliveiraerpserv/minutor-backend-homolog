<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Log de vigência das HORAS GARANTIDAS / mês (piso mínimo de cobrança do horista).
 * Espelha UserHourlyRateLog, porém campo único e com semântica de LIMPEZA:
 *   new_guaranteed_hours = null  →  a partir da vigência, NÃO há piso (campo apagado).
 * Por isso NÃO se usa `?? $user->guaranteed_hours` no valor novo vigente (null é intencional).
 */
class UserGuaranteedHoursLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'changed_by',
        'old_guaranteed_hours',
        'new_guaranteed_hours',
        'reason',
        'effective_from',
    ];

    protected $casts = [
        'effective_from'       => 'date',
        'old_guaranteed_hours' => 'decimal:2',
        'new_guaranteed_hours' => 'decimal:2',
    ];

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Horas garantidas (piso) vigentes NA competência (1º dia do mês).
     * - log já vigente (eff <= comp): valor NOVO do mais recente (null = sem piso).
     * - competência anterior ao 1º log: valor ANTIGO do 1º (o valor do "período X").
     * - sem log: valor atual do cadastro.
     * Retorna float (piso) ou null (sem piso).
     */
    public static function effectiveValueAt(int $userId, User $user, string $firstDayOfMonth, ?Collection $preloaded = null): ?float
    {
        $comp = Carbon::parse($firstDayOfMonth)->startOfMonth();

        $logs = ($preloaded ?? static::where('user_id', $userId)->orderBy('created_at')->get())
            ->map(fn ($l) => [
                'log' => $l,
                'eff' => $l->effective_from
                    ? Carbon::parse($l->effective_from)->startOfMonth()
                    : Carbon::parse($l->created_at)->startOfMonth()->addMonthNoOverflow(),
            ])
            ->sortBy(fn ($x) => $x['eff']->timestamp)
            ->values();

        $applicable = $logs->filter(fn ($x) => $x['eff']->lessThanOrEqualTo($comp))->last();
        if ($applicable) {
            $v = $applicable['log']->new_guaranteed_hours;
            return $v !== null ? (float) $v : null;
        }

        $first = $logs->first();
        if ($first) {
            $v = $first['log']->old_guaranteed_hours;
            return $v !== null ? (float) $v : null;
        }

        return $user->guaranteed_hours !== null ? (float) $user->guaranteed_hours : null;
    }

    /**
     * Versão em lote (evita N+1 ao valorizar vários consultores numa competência).
     * @param Collection<User> $users
     * @return array<int,?float> user_id => piso (ou null)
     */
    public static function effectiveValuesForUsers(Collection $users, string $firstDayOfMonth): array
    {
        $ids = $users->pluck('id')->all();
        $byUser = static::whereIn('user_id', $ids)->orderBy('created_at')->get()->groupBy('user_id');

        $out = [];
        foreach ($users as $u) {
            $out[$u->id] = static::effectiveValueAt($u->id, $u, $firstDayOfMonth, $byUser->get($u->id) ?? collect());
        }
        return $out;
    }
}
