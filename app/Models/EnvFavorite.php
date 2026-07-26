<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ambiente marcado como favorito por um usuário (acesso rápido). */
class EnvFavorite extends Model
{
    protected $table = 'env_favorites';

    protected $fillable = ['user_id', 'environment_id'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function environment(): BelongsTo { return $this->belongsTo(EnvEnvironment::class, 'environment_id'); }
}
