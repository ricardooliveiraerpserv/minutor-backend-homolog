<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CRM — evento/histórico imutável da oportunidade (timeline). */
class CrmOpportunityEvent extends Model
{
    public $timestamps = false; // só created_at

    protected $fillable = ['opportunity_id', 'event_type', 'field', 'from_value', 'to_value', 'triggered_by', 'meta', 'created_at'];
    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    public function triggeredBy(): BelongsTo { return $this->belongsTo(User::class, 'triggered_by'); }

    /** Registra um evento. */
    public static function log(int $opportunityId, string $type, array $attrs = []): self
    {
        return static::create(array_merge([
            'opportunity_id' => $opportunityId,
            'event_type'     => $type,
            'triggered_by'   => auth()->id(),
            'created_at'     => now(),
        ], $attrs));
    }
}
