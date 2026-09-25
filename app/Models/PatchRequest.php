<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** PATCH — intenção de aplicar um LOTE ordenado sobre uma base RPO comprovada (base_rpo_hash congelado). */
class PatchRequest extends Model
{
    protected $table = 'patch_requests';
    protected $guarded = ['id'];
    protected $casts = ['requested_at' => 'datetime'];

    public const MODE_FIXTURE = 'fixture';
    public const MODE_SIMULATED = 'simulated';
    public const MODE_LIVE = 'live';

    public const ST_OPEN = 'open';
    public const ST_EXECUTING = 'executing';
    public const ST_COMPLETED = 'completed';
    public const ST_FAILED = 'failed';
    public const ST_CANCELED = 'canceled';

    public function items(): HasMany
    {
        return $this->hasMany(PatchRequestItem::class, 'patch_request_id')->orderBy('batch_order');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(PatchExecution::class, 'patch_request_id');
    }
}
