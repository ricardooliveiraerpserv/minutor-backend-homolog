<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * GMUD — pacote governado de fontes (1 por ZIP recebido, atado ao chamado GMUD).
 *
 * O ZIP é RECEBIMENTO/EVIDÊNCIA — nunca gera commit por si só. A publicação (G7) é uma ação
 * posterior, governada e explicitamente confirmada. Segue a convenção da família source_docs:
 * SEM company_id. O Attachment referenciado é imutável (sha256/dedup/auditoria via AttachmentEvent).
 */
class GmudPackage extends Model
{
    protected $fillable = [
        'ticket_id', 'customer_id', 'source_repo_id', 'attachment_id',
        'original_name', 'size_bytes', 'sha256', 'uploaded_by', 'received_at',
        'classification', 'project_name', 'project_folder', 'status', 'error',
    ];

    protected $casts = [
        'size_bytes'  => 'integer',
        'received_at' => 'datetime',
    ];

    public const STATUS_RECEIVED   = 'received';
    public const STATUS_EXTRACTING = 'extracting';
    public const STATUS_ANALYZING  = 'analyzing';
    public const STATUS_ANALYZED   = 'analyzed';
    public const STATUS_FAILED     = 'failed';
    // Publicação (G7): estados da gravação atômica no Git.
    public const STATUS_PUBLISHING     = 'publishing';
    public const STATUS_PUBLISHED      = 'published';
    public const STATUS_PUBLISH_FAILED = 'publish_failed';

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(HelpDeskTicket::class, 'ticket_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sourceRepo(): BelongsTo
    {
        return $this->belongsTo(ClientSourceRepo::class, 'source_repo_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'attachment_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(GmudPackageFile::class);
    }
}
