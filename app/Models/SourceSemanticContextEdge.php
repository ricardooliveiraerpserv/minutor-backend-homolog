<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cross-source Fase 1 — grafo dependente→alvo (proveniência transitiva + invalidação GMUD). */
class SourceSemanticContextEdge extends Model
{
    protected $table = 'source_semantic_context_edge';
    protected $guarded = [];
    protected $casts = ['included_in_context' => 'bool', 'relevance_score' => 'float', 'candidates_count' => 'int', 'candidates_after_dedup' => 'int', 'est_context_tokens' => 'int'];
}
