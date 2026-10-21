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
        'effective_from',
    ];

    /**
     * Retorna os valores (hourly_rate, rate_type, consultant_type) em vigor na competência.
     * Vigência por log: a data ESCOLHIDA (effective_from) tem prioridade; logs legados sem
     * vigência caem no comportamento antigo (mudança vale a partir do MÊS SEGUINTE à troca).
     * Garante que alterar o valor NÃO mude fechamentos de meses anteriores ("legado intacto").
     */
    /**
     * @param \Illuminate\Support\Collection|null $preloadedLogs Logs JÁ carregados deste usuário
     *   (para evitar N+1 quando chamado em lote — ver effectiveValuesForUsers). Quando null (default),
     *   consulta como antes. A lógica de vigência é a MESMA nos dois casos.
     */
    public static function effectiveValuesAt(int $userId, \App\Models\User $user, string $firstDayOfMonth, ?\Illuminate\Support\Collection $preloadedLogs = null): array
    {
        $comp = \Carbon\Carbon::parse($firstDayOfMonth)->startOfMonth();

        $logs = ($preloadedLogs ?? static::where('user_id', $userId)
            ->orderBy('created_at')
            ->get())
            ->map(fn ($l) => [
                'log' => $l,
                'eff' => $l->effective_from
                    ? \Carbon\Carbon::parse($l->effective_from)->startOfMonth()
                    : \Carbon\Carbon::parse($l->created_at)->startOfMonth()->addMonthNoOverflow(),
            ])
            ->sortBy(fn ($x) => $x['eff']->timestamp)
            ->values();

        // Log já vigente NA competência (eff <= comp): usa os valores NOVOS do mais recente.
        $applicable = $logs->filter(fn ($x) => $x['eff']->lessThanOrEqualTo($comp))->last();
        if ($applicable) {
            $log = $applicable['log'];
            return [
                'hourly_rate'     => $log->new_hourly_rate     ?? $user->hourly_rate,
                'rate_type'       => $log->new_rate_type        ?? $user->rate_type,
                'consultant_type' => $log->new_consultant_type  ?? $user->consultant_type,
            ];
        }

        // Nenhum log vigente ainda, mas há reajuste FUTURO: a competência é ANTERIOR ao 1º
        // reajuste → usa os valores ANTIGOS (old_*) dele. NÃO usar user.* aqui: o cadastro
        // atual já reflete o reajuste futuro e usá-lo mudaria fechamentos passados (o bug).
        $firstFuture = $logs->first();
        if ($firstFuture) {
            $log = $firstFuture['log'];
            return [
                'hourly_rate'     => $log->old_hourly_rate     ?? $user->hourly_rate,
                'rate_type'       => $log->old_rate_type        ?? $user->rate_type,
                'consultant_type' => $log->old_consultant_type  ?? $user->consultant_type,
            ];
        }

        // Nunca houve reajuste registrado → valores atuais do cadastro.
        return [
            'hourly_rate'     => $user->hourly_rate,
            'rate_type'       => $user->rate_type,
            'consultant_type' => $user->consultant_type,
        ];
    }

    /**
     * Versão em LOTE de effectiveValuesAt: carrega os logs de TODOS os usuários numa query só
     * e aplica a mesma lógica por usuário (reusa effectiveValuesAt com os logs pré-carregados).
     * Resultado idêntico ao per-usuário — só elimina o N+1.
     *
     * @param \Illuminate\Support\Collection $users Coleção de App\Models\User
     * @return array<int,array{hourly_rate:mixed,rate_type:mixed,consultant_type:mixed}>
     */
    public static function effectiveValuesForUsers(\Illuminate\Support\Collection $users, string $firstDayOfMonth): array
    {
        $ids = $users->filter()->pluck('id')->unique()->filter()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $byUser = static::whereIn('user_id', $ids)
            ->orderBy('created_at')
            ->get()
            ->groupBy('user_id');

        $out = [];
        foreach ($users as $u) {
            if (!$u || isset($out[$u->id])) {
                continue;
            }
            $out[$u->id] = static::effectiveValuesAt($u->id, $u, $firstDayOfMonth, $byUser->get($u->id) ?? collect());
        }
        return $out;
    }

    /**
     * Igual a effectiveValuesAt, mas resolve por DATA EXATA (não colapsa pra startOfMonth).
     * Usado pela segmentação do mês da troca (vigência por dia). Mantém o mesmo fallback de
     * legado (log sem effective_from = vale a partir do mês SEGUINTE à troca).
     */
    public static function effectiveValuesAtDate(int $userId, \App\Models\User $user, string $date, ?\Illuminate\Support\Collection $preloadedLogs = null): array
    {
        $target = \Carbon\Carbon::parse($date)->startOfDay();

        $logs = ($preloadedLogs ?? static::where('user_id', $userId)->orderBy('created_at')->get())
            ->map(fn ($l) => [
                'log' => $l,
                'eff' => $l->effective_from
                    ? \Carbon\Carbon::parse($l->effective_from)->startOfDay()
                    : \Carbon\Carbon::parse($l->created_at)->startOfMonth()->addMonthNoOverflow(),
            ])
            ->sortBy(fn ($x) => $x['eff']->timestamp)
            ->values();

        $applicable = $logs->filter(fn ($x) => $x['eff']->lessThanOrEqualTo($target))->last();
        if ($applicable) {
            $log = $applicable['log'];
            return [
                'hourly_rate'     => $log->new_hourly_rate    ?? $user->hourly_rate,
                'rate_type'       => $log->new_rate_type       ?? $user->rate_type,
                'consultant_type' => $log->new_consultant_type ?? $user->consultant_type,
            ];
        }
        $firstFuture = $logs->first();
        if ($firstFuture) {
            $log = $firstFuture['log'];
            return [
                'hourly_rate'     => $log->old_hourly_rate    ?? $user->hourly_rate,
                'rate_type'       => $log->old_rate_type       ?? $user->rate_type,
                'consultant_type' => $log->old_consultant_type ?? $user->consultant_type,
            ];
        }
        return [
            'hourly_rate'     => $user->hourly_rate,
            'rate_type'       => $user->rate_type,
            'consultant_type' => $user->consultant_type,
        ];
    }

    /**
     * Segmenta uma competência quando houve TROCA de valor/tipo NO MEIO DO MÊS
     * (effective_from caindo em dia > início do período dentro do mês). Cada segmento carrega
     * a config vigente no seu início + os DIAS CORRIDOS cobertos (base do rateio proporcional:
     * valor_mensal × dias_do_segmento ÷ dias_do_mês).
     *
     * Retorna **1 segmento** (o mês inteiro) quando NÃO há troca no meio do mês — nesse caso o
     * chamador deve seguir o caminho legado (comportamento idêntico ao atual). >1 segmento = split.
     *
     * $employmentStart (bank_hours_start_date) limita o 1º segmento: admissão no meio do mês não
     * paga os dias anteriores. Admissão depois do mês → [] (sem pagamento).
     *
     * @return array<int,array{from:string,to:string,days:int,month_days:int,hourly_rate:mixed,rate_type:mixed,consultant_type:mixed}>
     */
    public static function segmentsForMonth(int $userId, \App\Models\User $user, string $ym, ?string $employmentStart = null, ?\Illuminate\Support\Collection $preloadedLogs = null): array
    {
        $monthStart = \Carbon\Carbon::parse($ym . '-01')->startOfMonth();
        $monthEnd   = (clone $monthStart)->endOfMonth();
        $monthDays  = (int) $monthStart->daysInMonth;

        // Admissão no meio do mês limita o início do período pago.
        $segStart = clone $monthStart;
        if ($employmentStart) {
            $es = \Carbon\Carbon::parse($employmentStart)->startOfDay();
            if ($es->format('Y-m') === $monthStart->format('Y-m') && $es->greaterThan($monthStart)) {
                $segStart = $es;
            } elseif ($es->format('Y-m') > $monthStart->format('Y-m')) {
                return []; // admissão depois da competência
            }
        }

        $logs = $preloadedLogs ?? static::where('user_id', $userId)->orderBy('created_at')->get();

        // Pontos de corte: effective_from de logs ESTRITAMENTE dentro do período (dia > início).
        $cuts = [];
        foreach ($logs as $l) {
            if (!$l->effective_from) continue;
            $eff = \Carbon\Carbon::parse($l->effective_from)->startOfDay();
            if ($eff->greaterThan($segStart) && $eff->lessThanOrEqualTo($monthEnd)) {
                $cuts[$eff->toDateString()] = true;
            }
        }
        $cutDates = array_keys($cuts);
        sort($cutDates);

        $bounds = array_merge([$segStart->toDateString()], $cutDates);
        $segments = [];
        for ($i = 0; $i < count($bounds); $i++) {
            $from = \Carbon\Carbon::parse($bounds[$i])->startOfDay();
            $to   = isset($bounds[$i + 1])
                ? \Carbon\Carbon::parse($bounds[$i + 1])->subDay()->startOfDay()
                : (clone $monthEnd);
            $cfg  = static::effectiveValuesAtDate($userId, $user, $from->toDateString(), $logs);
            $segments[] = [
                'from'            => $from->toDateString(),
                'to'              => $to->toDateString(),
                'days'            => $from->diffInDays($to) + 1,
                'month_days'      => $monthDays,
                'hourly_rate'     => $cfg['hourly_rate'],
                'rate_type'       => $cfg['rate_type'],
                'consultant_type' => $cfg['consultant_type'],
            ];
        }
        return $segments;
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
            'effective_from'  => 'date:Y-m-d',
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
