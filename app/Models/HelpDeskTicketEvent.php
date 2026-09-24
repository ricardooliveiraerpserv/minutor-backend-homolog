<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Help Desk — evento/histórico imutável do chamado (timeline, padrão CrmOpportunityEvent). */
class HelpDeskTicketEvent extends Model
{
    public $timestamps = false; // só created_at

    protected $table = 'helpdesk_ticket_events';

    protected $fillable = ['ticket_id', 'event_type', 'field', 'from_value', 'to_value', 'triggered_by', 'meta', 'created_at'];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    public function ticket(): BelongsTo      { return $this->belongsTo(HelpDeskTicket::class, 'ticket_id'); }
    public function triggeredBy(): BelongsTo { return $this->belongsTo(User::class, 'triggered_by'); }

    /** Registra um evento na timeline do chamado. */
    public static function log(int $ticketId, string $type, array $attrs = []): self
    {
        return static::create(array_merge([
            'ticket_id'    => $ticketId,
            'event_type'   => $type,
            'triggered_by' => auth()->id(),
            'created_at'   => now(),
        ], $attrs));
    }
}
