<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Entidade técnica pesquisável (C2) derivada do deterministic_json. READ-MODEL descartável.
 */
class SourceDocEntity extends Model
{
    protected $table = 'source_doc_entities';
    public $timestamps = false;

    protected $fillable = [
        'source_doc_id', 'source_doc_version_id', 'entity_type', 'name', 'parent',
        'access', 'risk_flags', 'line_start', 'line_end', 'customer_id', 'owner', 'repository',
    ];

    protected $casts = [
        'access'     => 'array',
        'risk_flags' => 'array',
    ];

    public const TYPES = ['function', 'table', 'field', 'query', 'integration', 'dependency', 'risk'];
}
