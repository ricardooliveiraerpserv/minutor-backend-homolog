<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Regra de TRANSFERÊNCIA DE APONTAMENTOS: todo apontamento do cliente ORIGEM que cair na janela
 * de datas (por digitação=created_at ou serviço=date) é movido para o projeto DESTINO — tanto os
 * existentes (aplicação retroativa) quanto os novos (Movidesk/HD/web), via hook no Timesheet.
 */
class TimesheetTransferRule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'source_customer_id', 'target_project_id',
        'date_field', 'date_from', 'date_until', 'active', 'override_manual', 'created_by',
    ];

    protected $casts = [
        'date_from'       => 'date',
        'date_until'      => 'date',
        'active'          => 'boolean',
        'override_manual' => 'boolean',
    ];

    public function sourceCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'source_customer_id');
    }

    public function targetProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'target_project_id');
    }
}
