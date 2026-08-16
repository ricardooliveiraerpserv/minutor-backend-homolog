<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Central de Fontes — C3. Auditoria + estado de execução das ações. params é SEMPRE metadata
 * operacional sanitizada — nunca código/prompt/token/secret/payload (garantido por sanitize()).
 */
class SourceDocActionLog extends Model
{
    protected $table = 'source_doc_action_log';

    protected $fillable = [
        'source_doc_id', 'version_id', 'action', 'layer', 'actor_user_id',
        'status', 'reason', 'idempotency_key', 'cost_usd', 'duration_ms', 'params',
    ];

    protected $casts = [
        'params'   => 'array',
        'cost_usd' => 'decimal:4',
    ];

    /** Chaves de metadata operacional PERMITIDAS em params. Tudo fora disso é descartado. */
    private const ALLOWED = [
        'blob_sha', 'commit_sha', 'situation', 'analysis_status', 'format', 'from_version',
        'to_version', 'force', 'reused', 'new_version_id', 'estimated_cost_usd', 'hard_limit_usd',
        'functions_count', 'bytes', 'ai_enabled', 'environment',
    ];

    /** Mantém só metadata operacional segura; corta qualquer conteúdo sensível/volumoso. */
    public static function sanitize(array $params): array
    {
        $out = [];
        foreach (self::ALLOWED as $k) {
            if (array_key_exists($k, $params)) {
                $v = $params[$k];
                // nunca guardar strings longas (evita vazar trecho de código/prompt por engano)
                if (is_string($v) && mb_strlen($v) > 80) {
                    $v = mb_substr($v, 0, 80);
                }
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
