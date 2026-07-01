<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Log de webhooks Clicksign — idempotência (event_id único) + auditoria do payload bruto. */
class ClicksignWebhookEvent extends Model
{
    protected $fillable = [
        'event_id', 'event_name', 'clicksign_envelope_id', 'payload', 'processed_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'processed_at' => 'datetime',
    ];
}
