<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma entrada do histórico de alterações de um fonte (uma por GMUD). */
class SourceDocChange extends Model
{
    protected $fillable = ['source_doc_id', 'ticket_id', 'ticket_number', 'responsavel', 'resumo'];

    public function doc(): BelongsTo
    {
        return $this->belongsTo(SourceDoc::class, 'source_doc_id');
    }
}
