<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bloco 4.2.1-A — read-model DERIVADO do reuso semântico por blob+contrato. Ver a migration.
 * NÃO é fonte da verdade (o semantic_json vivo é o de source_doc_versions).
 */
class SourceSemanticBlobCache extends Model
{
    protected $table = 'source_semantic_blob_cache';

    protected $fillable = [
        'blob_sha', 'facts_version', 'schema_version', 'prompt_version', 'model',
        'semantic_json', 'first_source_doc_id', 'hits',
    ];

    protected $casts = [
        'semantic_json' => 'array',
    ];
}
