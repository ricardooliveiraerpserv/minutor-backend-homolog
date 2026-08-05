<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Histórico de alteração de meta comercial (auditoria: quem, quando, valor anterior). */
class CrmSalesTargetHistory extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    public $timestamps = false;
    protected $table = 'crm_sales_target_history';
    protected $fillable = ['company_id', 'target_id', 'user_id', 'periodo', 'tipo', 'valor_anterior', 'valor_novo', 'observacao', 'changed_by_id', 'created_at'];
    protected $casts = ['valor_anterior' => 'decimal:2', 'valor_novo' => 'decimal:2', 'created_at' => 'datetime'];

    public function changedBy(): BelongsTo { return $this->belongsTo(User::class, 'changed_by_id'); }
}
