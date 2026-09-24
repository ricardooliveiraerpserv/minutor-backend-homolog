<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ledger de e-mails processados pela ingestão. PEGADINHA: $table explícito. */
class HelpDeskIngestedEmail extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $table = 'helpdesk_ingested_emails';

    protected $fillable = [
        'email_account_id', 'graph_message_id', 'from_email', 'subject',
        'action', 'reason', 'ticket_id', 'comment_id', 'received_at',
    ];

    protected $casts = ['received_at' => 'datetime'];

    public function account(): BelongsTo { return $this->belongsTo(HelpDeskEmailAccount::class, 'email_account_id'); }
    public function ticket(): BelongsTo { return $this->belongsTo(HelpDeskTicket::class, 'ticket_id'); }
}
