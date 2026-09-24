<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Log auditável de mesclagem/desmesclagem de chamados. */
class HelpDeskTicketMerge extends Model
{
    protected $table = 'helpdesk_ticket_merges';

    protected $fillable = [
        'action', 'target_ticket_id', 'source_ticket_id',
        'target_number', 'source_number', 'performed_by', 'options', 'meta',
    ];

    protected $casts = [
        'options' => 'array',
        'meta'    => 'array',
    ];

    public function target(): BelongsTo { return $this->belongsTo(HelpDeskTicket::class, 'target_ticket_id'); }
    public function source(): BelongsTo { return $this->belongsTo(HelpDeskTicket::class, 'source_ticket_id'); }
    public function performer(): BelongsTo { return $this->belongsTo(User::class, 'performed_by'); }
}
