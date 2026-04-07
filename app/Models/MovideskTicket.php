<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    ];

    protected $casts = [
        'solicitante' => 'array',
        'responsavel' => 'array',
    ];
}
