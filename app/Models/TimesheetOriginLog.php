<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de atribuição de ORIGEM dos apontamentos (Transferência de Apontamentos).
 * method: rule_auto (hook no save) | rule_apply (retroativo) | ticket (Movidesk/HD) |
 *         heuristic (data) | manual (informado pelo usuário) | native (lançado direto no destino).
 */
class TimesheetOriginLog extends Model
{
    protected $fillable = [
        'timesheet_id', 'source_customer_id', 'source_project_id',
        'rule_id', 'ticket', 'method', 'set_by', 'note',
    ];

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    public function sourceCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'source_customer_id');
    }

    public function sourceProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'source_project_id');
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
