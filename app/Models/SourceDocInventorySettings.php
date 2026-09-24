<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fase B — settings de inventário por escopo (allowlist de extensões elegíveis), independente do custo de IA.
 * NULL em inventory_extensions = herda; [] = override explícito (nenhuma extensão). Nunca converter [] em NULL.
 */
class SourceDocInventorySettings extends Model
{
    protected $table = 'source_doc_inventory_settings';

    public const SCOPES = ['global', 'customer', 'repo'];

    protected $fillable = ['scope_type', 'scope_id', 'inventory_extensions', 'updated_by'];

    protected $casts = [
        'inventory_extensions' => 'array', // NULL preservado como null; [] preservado como []
        'scope_id' => 'integer',
    ];
}
