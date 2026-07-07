<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Modelo reutilizável de comunicação externa. */
class CommunicationTemplate extends Model
{
    protected $table = 'communication_templates';

    protected $fillable = ['nome', 'tipo_comunicacao', 'title', 'message', 'structure', 'owner_id'];

    protected $casts = ['structure' => 'array'];

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
}
