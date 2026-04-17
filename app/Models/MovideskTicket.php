<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovideskTicket extends Model
{
    protected $fillable = [
        'ticket_id',
        'solicitante',
        'categoria',
        'urgencia',
        'responsavel',
        'nivel',
        'servico',
        'titulo',
        'status',
        'base_status',
        'origin',
        'owner_email',
        'owner_team',
        'user_id',
        'customer_id',
        'created_date',
        'closed_in',
        'resolved_in',
        'sla_response_date',
        'sla_real_response_date',
        'sla_solution_date',
        'sla_response_time',
        'sla_solution_time',
        'portal_synced_at',
    ];

    protected $casts = [
        'solicitante'            => 'array',
        'responsavel'            => 'array',
        'created_date'           => 'datetime',
        'closed_in'              => 'datetime',
        'resolved_in'            => 'datetime',
        'sla_response_date'      => 'datetime',
        'sla_real_response_date' => 'datetime',
        'sla_solution_date'      => 'datetime',
        'portal_synced_at'       => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
