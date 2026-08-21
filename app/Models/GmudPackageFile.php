<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GMUD — um arquivo extraído do pacote (evidência reproduzível + resultado do matching).
 *
 * `path_in_zip` é SÓ evidência — a autoridade do destino Git é a estrutura da Central, nunca o
 * path do ZIP. `match_status` (existing|new|ambiguous|identical) é resolvido de forma
 * DETERMINÍSTICA em G2 (basename + blob sha + árvore Git ao vivo); IA nunca decide fatos aqui.
 */
class GmudPackageFile extends Model
{
    protected $fillable = [
        'gmud_package_id', 'path_in_zip', 'filename', 'extension', 'size_bytes',
        'sha256', 'git_blob_sha', 'mtime', 'is_source',
        'match_status', 'matched_source_doc_id', 'matched_git_path',
        'match_candidates', 'match_evidence',
        'action', 'dest_git_path', 'old_git_path', 'published_blob_sha',
    ];

    protected $casts = [
        'size_bytes'       => 'integer',
        'is_source'        => 'boolean',
        'mtime'            => 'datetime',
        'match_candidates' => 'array',
        'match_evidence'   => 'array',
    ];

    /** Situações determinísticas do matching (G2). */
    public const MATCH_EXISTING  = 'existing';
    public const MATCH_NEW       = 'new';
    public const MATCH_AMBIGUOUS = 'ambiguous';
    public const MATCH_IDENTICAL = 'identical';

    /** Ação de publicação (G7). */
    public const ACTION_ADD    = 'add';
    public const ACTION_MODIFY = 'modify';
    public const ACTION_SKIP   = 'skip';

    public function package(): BelongsTo
    {
        return $this->belongsTo(GmudPackage::class, 'gmud_package_id');
    }

    public function matchedSourceDoc(): BelongsTo
    {
        return $this->belongsTo(SourceDoc::class, 'matched_source_doc_id');
    }
}
