<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Um login bem-sucedido (histórico). Ver tela "Acessos". */
class LoginLog extends Model
{
    public const UPDATED_AT = null; // só created_at

    protected $fillable = ['user_id', 'ip', 'user_agent', 'device', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }

    /** Registra um login best-effort (nunca derruba o login). */
    public static function record(User $user, \Illuminate\Http\Request $request): void
    {
        try {
            static::create([
                'user_id'    => $user->id,
                'ip'         => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 400),
                'device'     => mb_substr((string) ($request->input('device_name') ?? 'web'), 0, 120),
                'created_at' => now(),
            ]);
            $user->forceFill(['last_login_at' => now()])->saveQuietly();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('login_log.record_failed', ['user' => $user->id, 'err' => $e->getMessage()]);
        }
    }
}
