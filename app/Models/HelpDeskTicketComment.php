<?php

namespace App\Models;

use App\Attachments\Concerns\HasGlobalAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Help Desk — Interação do chamado (resposta/nota interna/linha de sistema). */
class HelpDeskTicketComment extends Model
{
    use SoftDeletes;
    use HasGlobalAttachments;

    protected $table = 'helpdesk_ticket_comments';

    /** Chave no AttachableEntitiesRegistry. */
    public static function attachmentEntityType(): string
    {
        return 'HELPDESK_TICKET_COMMENT';
    }

    protected $fillable = [
        'ticket_id', 'author_user_id', 'author_contact_id',
        'body', 'visibility', 'is_system', 'channel', 'idempotency_key',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public const VISIBILITIES = ['internal', 'customer'];

    public function ticket(): BelongsTo  { return $this->belongsTo(HelpDeskTicket::class, 'ticket_id'); }
    public function author(): BelongsTo  { return $this->belongsTo(User::class, 'author_user_id'); }
    public function contact(): BelongsTo { return $this->belongsTo(CustomerContact::class, 'author_contact_id'); }

    public function scopeVisibleToCustomer($query)
    {
        return $query->where('visibility', 'customer');
    }
}
