<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

/**
 * Regra de multiplicação de horas faturáveis ao cliente, por contrato.
 * Ver migration 2026_07_23_170000_create_contract_hour_multipliers.
 */
class ContractHourMultiplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'contract_id', 'customer_id', 'percent', 'start_date', 'end_date',
        'active', 'reason', 'created_by_id',
    ];

    protected $casts = [
        'percent'    => 'decimal:2',
        'start_date' => 'date',
        'end_date'   => 'date',
        'active'     => 'boolean',
    ];

    public function contract()  { return $this->belongsTo(Contract::class); }
    public function customer()  { return $this->belongsTo(Customer::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by_id'); }

    /** Fator multiplicador desta regra: 50% → 1.5 ; 100% → 2.0 */
    public function factor(): float
    {
        return 1.0 + ((float) $this->percent) / 100.0;
    }

    /** Regras ativas que valem numa data (dentro da vigência; end_date NULL = sem fim). */
    public function scopeEffectiveOn(Builder $q, $date): Builder
    {
        $d = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;
        return $q->where('active', true)
            ->whereDate('start_date', '<=', $d)
            ->where(fn ($w) => $w->whereNull('end_date')->orWhereDate('end_date', '>=', $d));
    }
}
