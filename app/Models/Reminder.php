<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lembrete pessoal (sempre escopado ao user_id — nunca compartilhado). */
class Reminder extends Model
{
    protected $fillable = ['user_id', 'text', 'due_date', 'due_time', 'completed'];

    protected $casts = [
        'due_date'  => 'date:Y-m-d',
        'completed' => 'boolean',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
