<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cross-source Fase 1 — índice "quem define o símbolo" (read-model derivado do determinístico). */
class SourceSymbolDefinition extends Model
{
    protected $table = 'source_symbol_definition';
    protected $guarded = [];
    protected $casts = ['is_user_function' => 'bool', 'writes' => 'bool', 'touches_tables' => 'int', 'start_line' => 'int', 'end_line' => 'int'];
}
