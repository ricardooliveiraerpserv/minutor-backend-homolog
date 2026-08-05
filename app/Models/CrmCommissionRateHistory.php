<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Auditoria de alteração de exceção/política de comissão (quem, quando, motivo, IP). */
class CrmCommissionRateHistory extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    public $timestamps = false;
    protected $table = 'crm_commission_rate_history';
    protected $fillable = ['company_id', 'user_id', 'valor_anterior', 'valor_novo', 'campo', 'motivo', 'changed_by_id', 'ip', 'created_at'];
    protected $casts = ['valor_anterior' => 'decimal:2', 'valor_novo' => 'decimal:2', 'created_at' => 'datetime'];

    public function changedBy(): BelongsTo { return $this->belongsTo(User::class, 'changed_by_id'); }
}
