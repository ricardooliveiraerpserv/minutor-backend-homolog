<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Histórico de eventos de negócio por ambiente (≠ auditoria de acesso). */
class EnvHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'env_history';

    protected $fillable = ['environment_id', 'user_id', 'kind', 'description', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public static function log(int $environmentId, ?int $userId, string $kind, string $description, array $meta = []): void
    {
        static::create([
            'environment_id' => $environmentId,
            'user_id'        => $userId,
            'kind'           => $kind,
            'description'    => $description,
            'meta'           => $meta,
        ]);
    }
}
