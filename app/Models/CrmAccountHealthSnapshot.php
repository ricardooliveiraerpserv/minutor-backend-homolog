<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Roadmap Fase 1 — snapshot de Saúde da Conta (histórico). */
class CrmAccountHealthSnapshot extends Model
{
    public $timestamps = false; // só created_at

    protected $fillable = ['customer_id', 'score', 'status', 'motivos', 'margem', 'competencia', 'created_at'];
    protected $casts = ['motivos' => 'array', 'margem' => 'decimal:2', 'competencia' => 'date', 'created_at' => 'datetime', 'score' => 'integer'];
}
