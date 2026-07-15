<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExcessHourCharge extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $table = 'excess_hour_charges';

    protected $fillable = [
        'project_id', 'year_month', 'basis',
        'contracted_hours', 'consumed_hours', 'excess_hours',
        'additional_hourly_rate', 'excess_value',
        'status', 'notes', 'closed_at', 'closed_by_id',
    ];

    protected $casts = [
        'contracted_hours'       => 'decimal:2',
        'consumed_hours'         => 'decimal:2',
        'excess_hours'           => 'decimal:2',
        'additional_hourly_rate' => 'decimal:2',
        'excess_value'           => 'decimal:2',
        'closed_at'              => 'datetime',
    ];

    public const STATUS_PENDENTE   = 'pendente';
    public const STATUS_COBRADO    = 'cobrado';
    public const STATUS_NAO_COBRAR = 'nao_cobrar';

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }
}
