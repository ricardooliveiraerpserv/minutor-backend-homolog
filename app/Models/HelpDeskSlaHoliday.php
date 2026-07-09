<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Help Desk — feriado específico de uma política/contrato de SLA. */
class HelpDeskSlaHoliday extends Model
{
    protected $table = 'helpdesk_sla_holidays';

    protected $fillable = ['sla_policy_id', 'date', 'name'];

    protected $casts = ['date' => 'date:Y-m-d'];

    public function policy(): BelongsTo { return $this->belongsTo(HelpDeskSlaPolicy::class, 'sla_policy_id'); }
}
