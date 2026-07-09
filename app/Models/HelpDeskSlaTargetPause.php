<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Help Desk — status que pausa o SLA de UMA regra/prioridade (por target). */
class HelpDeskSlaTargetPause extends Model
{
    protected $table = 'helpdesk_sla_target_pauses';

    protected $fillable = ['sla_target_id', 'status_key'];

    public function target(): BelongsTo { return $this->belongsTo(HelpDeskSlaTarget::class, 'sla_target_id'); }
}
