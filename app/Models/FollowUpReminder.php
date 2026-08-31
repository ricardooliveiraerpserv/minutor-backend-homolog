<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Log de cobrança enviada (idempotência do scheduler). created_at via default do banco.
 */
class FollowUpReminder extends Model
{
    public $timestamps = false;

    protected $fillable = ['follow_up_id', 'kind', 'sent_on'];

    protected $casts = ['sent_on' => 'date:Y-m-d'];
}
