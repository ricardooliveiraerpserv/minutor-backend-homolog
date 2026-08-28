<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PATCH P2 — item do lote na execução: journal durável por item (started/committed) para prova causal parcial. */
class PatchExecutionItem extends Model
{
    protected $table = 'patch_execution_items';
    protected $guarded = ['id'];
    protected $casts = ['started_at' => 'datetime', 'committed_at' => 'datetime'];
}
