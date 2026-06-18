<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** CRM — campanha comercial. */
class CrmCampaign extends Model
{
    protected $fillable = ['name', 'starts_at', 'ends_at', 'active'];
    protected $casts = ['starts_at' => 'date', 'ends_at' => 'date', 'active' => 'boolean'];
}
