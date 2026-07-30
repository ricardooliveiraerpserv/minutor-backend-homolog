<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de exclusão de requisição/contrato no pipeline (Demandas e Projetos).
 * Registro de auditoria imutável: quem excluiu, quando, o quê e por quê.
 */
class ContractDeletionLog extends Model
{
    protected $fillable = [
        'contract_id', 'contract_name', 'customer_name', 'kanban_status',
        'deleted_by', 'deleted_by_name', 'reason', 'snapshot', 'company_id',
    ];

    protected $casts = [
        'snapshot' => 'array',
    ];

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
