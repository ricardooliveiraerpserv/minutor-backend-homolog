<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CRM — evento/histórico imutável no nível da EMPRESA (timeline desde a fase Lead). */
class CrmCustomerEvent extends Model
{
    public $timestamps = false; // só created_at

    protected $fillable = ['customer_id', 'event_type', 'label', 'meta', 'triggered_by', 'created_at'];
    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    public function triggeredBy(): BelongsTo { return $this->belongsTo(User::class, 'triggered_by'); }

    /** Registra um evento da empresa. */
    public static function log(int $customerId, string $type, ?string $label = null, array $meta = []): self
    {
        return static::create([
            'customer_id'  => $customerId,
            'event_type'   => $type,
            'label'        => $label,
            'meta'         => $meta ?: null,
            'triggered_by' => auth()->id(),
            'created_at'   => now(),
        ]);
    }
}
