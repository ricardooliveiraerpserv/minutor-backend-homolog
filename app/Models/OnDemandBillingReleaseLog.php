<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de auditoria (append-only) de uma ação de liberação/estorno de faturamento
 * On Demand. Cada toggle em OnDemandBillingReleaseController grava uma linha aqui.
 *
 * @property string $action 'release' | 'revoke'
 */
class OnDemandBillingReleaseLog extends Model
{
    public const ACTION_RELEASE = 'release';
    public const ACTION_REVOKE  = 'revoke';

    protected $fillable = [
        'project_id',
        'year_month',
        'action',
        'user_id',
        'reason',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
