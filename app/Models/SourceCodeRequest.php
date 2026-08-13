<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fase 1 — cabeçalho de uma solicitação de código-fonte (1 chamado pode ter várias). */
class SourceCodeRequest extends Model
{
    protected $fillable = ['ticket_id', 'client_id', 'requested_by', 'comment_id', 'status'];

    public function items(): HasMany
    {
        return $this->hasMany(SourceCodeRequestItem::class, 'request_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(HelpDeskTicket::class, 'ticket_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
