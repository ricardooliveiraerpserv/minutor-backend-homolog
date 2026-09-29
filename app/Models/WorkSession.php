<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sessão de Trabalho — conceito de plataforma (help_desk, comercial, aprovacao…). */
class WorkSession extends Model
{
    protected $fillable = ['scope', 'user_id', 'started_at', 'ended_at', 'context'];
    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime', 'context' => 'array'];

    public function events(): HasMany { return $this->hasMany(WorkSessionEvent::class); }
}
