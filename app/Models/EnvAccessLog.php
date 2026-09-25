<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/** Auditoria de acesso do Cofre de Ambientes. NUNCA o valor do segredo, só metadados. */
class EnvAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'env_access_logs';

    protected $fillable = ['user_id', 'environment_id', 'secret_id', 'item_label', 'action', 'justification', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function environment(): BelongsTo { return $this->belongsTo(EnvEnvironment::class, 'environment_id'); }

    public static function record(Request $request, string $action, array $attrs = [], ?string $justification = null): void
    {
        static::create(array_merge([
            'user_id'       => $request->user()?->id,
            'action'        => $action,
            'justification' => $justification,
            'meta'          => [
                'ip'         => $request->ip(),
                'user_agent' => (string) str($request->userAgent() ?? '')->limit(255),
            ],
        ], $attrs));
    }
}
