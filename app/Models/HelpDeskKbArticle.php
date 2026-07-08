<?php

namespace App\Models;

use App\Attachments\Concerns\HasGlobalAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Help Desk — Base de Conhecimento: artigo. Imagens/anexos via Attachment Engine. */
class HelpDeskKbArticle extends Model
{
    use SoftDeletes;
    use HasGlobalAttachments;

    protected $table = 'helpdesk_kb_articles';

    protected $fillable = [
        'kb_category_id', 'title', 'slug', 'excerpt', 'body',
        'status', 'visibility', 'author_user_id', 'published_at',
        'views_count', 'helpful_count', 'not_helpful_count', 'pinned',
    ];

    protected $casts = [
        'published_at'      => 'datetime',
        'views_count'       => 'integer',
        'helpful_count'     => 'integer',
        'not_helpful_count' => 'integer',
        'pinned'            => 'boolean',
    ];

    public const STATUSES     = ['draft', 'published', 'archived'];
    public const VISIBILITIES = ['customer', 'internal'];

    /** Chave no AttachableEntitiesRegistry. */
    public static function attachmentEntityType(): string
    {
        return 'HELPDESK_KB_ARTICLE';
    }

    public function category(): BelongsTo { return $this->belongsTo(HelpDeskKbCategory::class, 'kb_category_id'); }
    public function author(): BelongsTo   { return $this->belongsTo(User::class, 'author_user_id'); }
    public function feedback(): HasMany   { return $this->hasMany(HelpDeskKbArticleFeedback::class, 'kb_article_id'); }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }
}
