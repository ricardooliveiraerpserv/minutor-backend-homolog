<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Catálogo de Serviços do Help Desk (árvore). PEGADINHA: $table explícito (Laravel inferiria help_desk_services). */
class HelpDeskService extends Model
{
    use SoftDeletes;

    protected $table = 'helpdesk_services';

    protected $fillable = [
        'parent_id', 'name', 'code', 'availability',
        'visible_to_agent', 'visible_to_client', 'selectable_by_agent', 'selectable_by_client',
        'allow_conclusion', 'active', 'sort_order',
    ];

    protected $casts = [
        'visible_to_agent'     => 'boolean',
        'visible_to_client'    => 'boolean',
        'selectable_by_agent'  => 'boolean',
        'selectable_by_client' => 'boolean',
        'allow_conclusion'     => 'boolean',
        'active'               => 'boolean',
        'sort_order'           => 'integer',
    ];

    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_id'); }
}
