<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Help Desk — Meta de SLA por prioridade dentro de uma política. */
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
}
