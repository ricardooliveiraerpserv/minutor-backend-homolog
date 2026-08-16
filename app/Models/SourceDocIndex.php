<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * READ-MODEL derivado (C2) — resumo por fonte. NÃO é fonte de verdade; reconstruível do
 * deterministic_json. Não guarda situação Git (essa é do SourceDocStatusResolver).
 */
class SourceDocIndex extends Model
{
    protected $table = 'source_doc_index';
    protected $primaryKey = 'source_doc_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'source_doc_id', 'indexed_version_id', 'indexed_blob_sha',
        'functions_count', 'tables_count', 'queries_count',
        'has_risk', 'risk_flags', 'integrations',
        'customer_id', 'owner', 'repository', 'branch', 'lang', 'tipo',
        'analysis_status', 'semantic_quality', 'indexed_at',
    ];

    protected $casts = [
        'risk_flags'   => 'array',
        'integrations' => 'array',
        'has_risk'     => 'boolean',
        'indexed_at'   => 'datetime',
    ];
}
