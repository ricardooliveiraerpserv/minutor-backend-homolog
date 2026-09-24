<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Help Desk — Feedback de artigo da Base de Conhecimento ("isso foi útil?"). */
class HelpDeskKbArticleFeedback extends Model
{
    protected $table = 'helpdesk_kb_article_feedback';

    protected $fillable = ['kb_article_id', 'user_id', 'customer_contact_id', 'helpful', 'comment', 'ip'];

    protected $casts = ['helpful' => 'boolean'];

    public function article(): BelongsTo { return $this->belongsTo(HelpDeskKbArticle::class, 'kb_article_id'); }
    public function user(): BelongsTo    { return $this->belongsTo(User::class); }
    public function contact(): BelongsTo { return $this->belongsTo(CustomerContact::class, 'customer_contact_id'); }
}
