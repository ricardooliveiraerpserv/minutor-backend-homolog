<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** C5.1 — artefato de RPO registrado (imutável após registered; correção = nova revisão). */
class RpoArtifact extends Model
{
    protected $table = 'rpo_artifacts';
    protected $guarded = ['id'];
    protected $casts = ['compatibility' => 'array', 'registered_at' => 'datetime'];
}
