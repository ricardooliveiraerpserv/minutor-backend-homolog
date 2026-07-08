<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Evento de uma Sessão de Trabalho (durável p/ métricas e IA futura). */
class WorkSessionEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['work_session_id', 'type', 'entity_type', 'entity_id', 'metadata', 'created_at'];
    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];
}
