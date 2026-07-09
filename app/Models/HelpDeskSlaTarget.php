<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Help Desk — Meta de SLA por prioridade dentro de uma política (a "regra"). */
class HelpDeskSlaTarget extends Model
{
    protected $table = 'helpdesk_sla_targets';

    protected $fillable = [
        'sla_policy_id', 'priority', 'first_response_minutes', 'resolution_minutes',
    ];

    protected $casts = [
        'first_response_minutes' => 'integer',
        'resolution_minutes'     => 'integer',
    ];

    public function policy(): BelongsTo { return $this->belongsTo(HelpDeskSlaPolicy::class, 'sla_policy_id'); }

    /** Status que PAUSAM o SLA desta regra/prioridade (lista própria). */
    public function pauses(): HasMany { return $this->hasMany(HelpDeskSlaTargetPause::class, 'sla_target_id'); }

    /** Chaves de status pausantes desta regra (array de status_key). */
    public function pauseKeys(): array
    {
        $rows = $this->relationLoaded('pauses') ? $this->pauses : $this->pauses()->get();
        return $rows->pluck('status_key')->all();
    }
}
