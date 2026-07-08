<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Help Desk — Base de Conhecimento: categoria (árvore). */
class HelpDeskKbCategory extends Model
{
    protected $table = 'helpdesk_kb_categories';

    protected $fillable = [
        'parent_id', 'name', 'slug', 'description', 'icon', 'visibility', 'active', 'sort_order',
    ];

    protected $casts = [
        'active'     => 'boolean',
        'sort_order' => 'integer',
    ];

    public const VISIBILITIES = ['customer', 'internal'];

    public function parent(): BelongsTo   { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany   { return $this->hasMany(self::class, 'parent_id'); }
    public function articles(): HasMany   { return $this->hasMany(HelpDeskKbArticle::class, 'kb_category_id'); }
}
