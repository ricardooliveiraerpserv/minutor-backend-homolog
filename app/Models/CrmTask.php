<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** CRM — tarefa comercial / Próxima Ação. */
class CrmTask extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $fillable = [
        'customer_id', 'opportunity_id', 'contract_id', 'project_id', 'activity_id',
        'tipo', 'categoria', 'titulo', 'objetivo', 'data', 'responsavel_id', 'prioridade', 'concluida_at', 'notas', 'created_by_id',
    ];

    protected $casts = ['data' => 'datetime', 'concluida_at' => 'datetime'];

    /** Canal da interação. */
    public const TIPOS = ['ligacao', 'whatsapp', 'email', 'reuniao', 'visita', 'demo'];
    /** Natureza do relacionamento (Fase 7) — Follow-up ≠ atividade de execução. */
    public const CATEGORIAS = ['retorno', 'proposta', 'reclamacao', 'aprovacao', 'sinalizou_renovacao', 'reuniao', 'outro'];

    public function customer(): BelongsTo    { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function opportunity(): BelongsTo { return $this->belongsTo(CrmOpportunity::class, 'opportunity_id'); }
    public function contract(): BelongsTo    { return $this->belongsTo(Contract::class, 'contract_id'); }
    public function project(): BelongsTo     { return $this->belongsTo(Project::class, 'project_id'); }
    public function responsavel(): BelongsTo { return $this->belongsTo(User::class, 'responsavel_id'); }
    /** Múltiplos responsáveis pela tarefa (além do vendedor). responsavel_id = primário/legado. */
    public function responsaveis(): BelongsToMany { return $this->belongsToMany(User::class, 'crm_task_responsaveis', 'crm_task_id', 'user_id')->withTimestamps(); }
}
