<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** C5.1 — alvo LÓGICO de RPO (cadastral + confirmação por observação). */
class RpoTarget extends Model
{
    protected $table = 'rpo_targets';
    protected $guarded = ['id'];
    protected $casts = ['confirmed_at' => 'datetime', 'last_successfully_published' => 'array'];

    public function appservers()
    {
        return $this->hasMany(RpoTargetAppserver::class, 'rpo_target_id');
    }
}
