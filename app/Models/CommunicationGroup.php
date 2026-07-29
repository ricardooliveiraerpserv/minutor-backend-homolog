<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grupo de distribuição estruturado em blocos por cliente (Central de Comunicação). */
class CommunicationGroup extends Model
{
    protected $table = 'communication_groups';
    protected $fillable = ['nome', 'owner_id'];

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function blocks(): HasMany { return $this->hasMany(CommunicationGroupBlock::class, 'group_id')->orderBy('sort_order')->orderBy('id'); }
}
