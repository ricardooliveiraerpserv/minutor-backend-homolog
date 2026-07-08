<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Help Desk — Seguidor (CC) do chamado: usuário interno, contato do cliente ou e-mail avulso. */
class HelpDeskTicketWatcher extends Model
{
    protected $table = 'helpdesk_ticket_watchers';

    protected $fillable = ['ticket_id', 'user_id', 'customer_contact_id', 'email'];

    public function ticket(): BelongsTo  { return $this->belongsTo(HelpDeskTicket::class, 'ticket_id'); }
    public function user(): BelongsTo    { return $this->belongsTo(User::class); }
    public function contact(): BelongsTo { return $this->belongsTo(CustomerContact::class, 'customer_contact_id'); }
}
