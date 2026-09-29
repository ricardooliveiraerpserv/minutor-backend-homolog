<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Achado durável de uma Análise de Qualidade (P2). Persistido no Postgres = autoridade histórica.
 * NUNCA guarda snippet/código-fonte — só metadados. O trecho de código (view_git) é reconstruído
 * sob demanda a partir da versão/blob, jamais lido daqui.
 */
class SourceDocQualityFinding extends Model
{
    protected $table = 'source_doc_quality_findings';

    protected $fillable = [
        'source_doc_quality_analysis_id', 'position',
        'rule', 'severity', 'analyzer_severity', 'category', 'title', 'description',
        'recommendation', 'file', 'line', 'start_line', 'col', 'occurrences', 'meta',
    ];

    protected $casts = [
        'position'    => 'integer',
        'line'        => 'integer',
        'start_line'  => 'integer',
        'col'         => 'integer',
        'occurrences' => 'integer',
        'meta'        => 'array',
    ];

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(SourceDocQualityAnalysis::class, 'source_doc_quality_analysis_id');
    }
}
