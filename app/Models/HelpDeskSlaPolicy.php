<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Help Desk — Política de SLA (escopável por contrato/cliente, ou global/default). */
class HelpDeskSlaPolicy extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    use SoftDeletes;

    protected $table = 'helpdesk_sla_policies';

    protected $fillable = [
        'name', 'description', 'customer_id', 'contract_id',
        'business_hours', 'timezone', 'use_national_holidays', 'is_default', 'active',
    ];

    protected $casts = [
        'business_hours'        => 'array',
        'use_national_holidays' => 'boolean',
        'is_default'            => 'boolean',
        'active'                => 'boolean',
    ];

    /** Fuso para o cálculo de horas úteis (janelas em horário local). */
    public function slaTimezone(): string
    {
        return $this->timezone ?: 'America/Sao_Paulo';
    }

    /**
     * Janelas de atendimento normalizadas: isoWeekday (1=Seg…7=Dom) → [[inícioMin,fimMin], …].
     * business_hours esperado: { "1": [["09:00","12:00"],["13:00","18:00"]], …, "6": [], "7": [] }.
     * Vazio/null → [] (o relógio trata como 24x7 = horas corridas, retrocompat).
     */
    public function windowsByWeekday(): array
    {
        $bh = $this->business_hours;
        if (!is_array($bh) || empty($bh)) return [];
        $out = [];
        foreach ($bh as $iso => $wins) {
            $day = (int) $iso;
            $out[$day] = [];
            foreach ((array) $wins as $w) {
                $s = $w[0] ?? null; $e = $w[1] ?? null;
                if (!$s || !$e) continue;
                $out[$day][] = [self::hhmmToMin($s), self::hhmmToMin($e)];
            }
        }
        return $out;
    }

    /**
     * Datas de feriado (Y-m-d) que não contam no SLA: nacionais globais (se use_national_holidays)
     * + feriados específicos desta política/contrato.
     */
    public function holidayDates(): array
    {
        $dates = [];
        if ($this->use_national_holidays ?? true) {
            $dates = Holiday::query()->active()
                ->pluck('date')->map(fn ($d) => \Carbon\Carbon::parse($d)->format('Y-m-d'))->all();
        }
        // Feriados do contrato: data exata; se "yearly" (repete todo ano), expande p/ uma janela
        // de anos (ano-1 .. ano+3), casando por dia/mês — cobre qualquer cálculo de SLA.
        $own = [];
        $curY = (int) now()->year;
        foreach (($this->relationLoaded('holidays') ? $this->holidays : $this->holidays()->get()) as $h) {
            $d = \Carbon\Carbon::parse($h->date);
            if ($h->yearly ?? false) {
                for ($y = $curY - 1; $y <= $curY + 3; $y++) $own[] = $y . '-' . $d->format('m-d');
            } else {
                $own[] = $d->format('Y-m-d');
            }
        }
        return array_values(array_unique(array_merge($dates, $own)));
    }

    private static function hhmmToMin(string $hhmm): int
    {
        [$h, $m] = array_pad(array_map('intval', explode(':', $hhmm)), 2, 0);
        return $h * 60 + $m;
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function contract(): BelongsTo { return $this->belongsTo(Contract::class); }

    /** Clientes vinculados a esta política (usam este SLA). N:N. */
    public function customers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'helpdesk_sla_policy_customers', 'sla_policy_id', 'customer_id')->withTimestamps();
    }
    public function targets(): HasMany    { return $this->hasMany(HelpDeskSlaTarget::class, 'sla_policy_id'); }
    public function tickets(): HasMany    { return $this->hasMany(HelpDeskTicket::class, 'sla_policy_id'); }
    public function holidays(): HasMany   { return $this->hasMany(HelpDeskSlaHoliday::class, 'sla_policy_id'); }

    /** Meta de SLA para uma prioridade. */
    public function targetFor(string $priority): ?HelpDeskSlaTarget
    {
        $targets = $this->relationLoaded('targets') ? $this->targets : $this->targets()->get();
        return $targets->firstWhere('priority', $priority);
    }

    public static function defaultPolicy(): ?self
    {
        return static::where('is_default', true)->where('active', true)->first();
    }
}
