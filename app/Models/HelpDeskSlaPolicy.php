<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Help Desk — Política de SLA (escopável por contrato/cliente, ou global/default). */
class HelpDeskSlaPolicy extends Model
{
    use SoftDeletes;

    protected $table = 'helpdesk_sla_policies';

    protected $fillable = [
        'name', 'description', 'customer_id', 'contract_id',
        'business_hours', 'is_default', 'active',
    ];

    protected $casts = [
        'business_hours' => 'array',
        'is_default'     => 'boolean',
        'active'         => 'boolean',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function contract(): BelongsTo { return $this->belongsTo(Contract::class); }
    public function targets(): HasMany    { return $this->hasMany(HelpDeskSlaTarget::class, 'sla_policy_id'); }
    public function tickets(): HasMany    { return $this->hasMany(HelpDeskTicket::class, 'sla_policy_id'); }

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
