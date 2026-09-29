<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Help Desk — Categoria de chamado (árvore). Roteia fila + SLA padrão. */
class HelpDeskCategory extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    use SoftDeletes;

    protected $table = 'helpdesk_categories';

    protected $fillable = [
        'parent_id', 'name', 'slug', 'description', 'color',
        'default_team_id', 'sla_policy_id', 'active', 'sort_order',
    ];

    protected $casts = [
        'active'     => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo    { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany    { return $this->hasMany(self::class, 'parent_id'); }
    public function defaultTeam(): BelongsTo { return $this->belongsTo(HelpDeskTeam::class, 'default_team_id'); }
    public function slaPolicy(): BelongsTo  { return $this->belongsTo(HelpDeskSlaPolicy::class, 'sla_policy_id'); }
    public function tickets(): HasMany      { return $this->hasMany(HelpDeskTicket::class, 'category_id'); }
}
