<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comissão apurada de uma oportunidade ganha. Máquina de status:
 *   apurada → aprovada → paga ; e bloqueada/cancelada (com motivo).
 */
class CrmCommission extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $fillable = [
        'company_id', 'opportunity_id', 'user_id', 'competencia', 'base', 'percentual', 'valor',
        'status', 'approved_by_id', 'approved_at', 'paid_at', 'motivo', 'created_by_id',
    ];
    protected $casts = [
        'base' => 'decimal:2', 'percentual' => 'decimal:2', 'valor' => 'decimal:2',
        'approved_at' => 'datetime', 'paid_at' => 'datetime',
    ];

    /** Transições permitidas da máquina de status. */
    public const TRANSITIONS = [
        'apurada'   => ['aprovada', 'bloqueada', 'cancelada'],
        'aprovada'  => ['paga', 'bloqueada', 'cancelada'],
        'bloqueada' => ['apurada', 'aprovada', 'cancelada'],
        'paga'      => [],
        'cancelada' => [],
    ];

    public const PENDENTES = ['apurada', 'aprovada'];

    public function opportunity(): BelongsTo { return $this->belongsTo(CrmOpportunity::class, 'opportunity_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
