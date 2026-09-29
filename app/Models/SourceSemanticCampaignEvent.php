<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Auditoria da campanha (sanitizada: sem código/prompt/secret). */
class SourceSemanticCampaignEvent extends Model
{
    protected $table = 'source_semantic_campaign_events';
    public $timestamps = false;

    protected $fillable = ['campaign_id', 'actor_user_id', 'event', 'meta', 'created_at'];
    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];
}
