<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/** Auditoria do Cofre — quem/quando/qual ação. NUNCA conteúdo ou blobs no meta. */
class VaultAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'vault_id', 'item_id', 'item_name', 'action', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function vault(): BelongsTo { return $this->belongsTo(Vault::class); }
    public function item(): BelongsTo { return $this->belongsTo(VaultItem::class, 'item_id'); }

    /** Registra uma ação de auditoria com ip/user_agent do request. */
    public static function record(Request $request, string $action, array $attrs = [], array $meta = []): void
    {
        static::create(array_merge([
            'user_id' => $request->user()?->id,
            'action'  => $action,
            'meta'    => array_merge([
                'ip'         => $request->ip(),
                'user_agent' => (string) str($request->userAgent() ?? '')->limit(255),
            ], $meta),
        ], $attrs));
    }
}
