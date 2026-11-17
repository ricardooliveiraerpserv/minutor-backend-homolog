<?php

namespace App\Models\Financeiro;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Trilha de auditoria do Financeiro (certificado, credenciais). Nunca grava segredo. */
class FinAuditLog extends Model
{
    protected $table = 'fin_audit_logs';
    public $timestamps = false;

    protected $fillable = ['company_id', 'user_id', 'acao', 'alvo_tipo', 'alvo_id', 'detalhes', 'ip', 'created_at'];

    protected $casts = ['detalhes' => 'array', 'created_at' => 'datetime'];

    public static function registrar(Request $request, ?int $companyId, string $acao, ?Model $alvo = null, array $detalhes = []): void
    {
        static::create([
            'company_id' => $companyId,
            'user_id' => $request->user()?->id,
            'acao' => $acao,
            'alvo_tipo' => $alvo ? class_basename($alvo) : null,
            'alvo_id' => $alvo?->getKey(),
            'detalhes' => $detalhes ?: null,
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
