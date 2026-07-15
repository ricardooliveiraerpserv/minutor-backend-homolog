<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inclusão manual na rotina de reajuste (sem contrato). Suporta reajuste
 * (aplicar índice + histórico) e destinatários de e-mail (do cliente vinculado
 * ou digitados/salvos). Ver migrations.
 */
class ManualReajuste extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $table = 'manual_reajustes';

    protected $fillable = [
        'cliente_nome', 'customer_id', 'project_id', 'descricao', 'empresa', 'valor_inicial',
        'data_assinatura', 'data_ultimo_reajuste', 'data_vencimento',
        'taxa_reajuste', 'pct_reajuste', 'notify_emails',
    ];

    protected $casts = [
        'valor_inicial'        => 'decimal:2',
        'pct_reajuste'         => 'decimal:3',
        'data_assinatura'      => 'date:Y-m-d',
        'data_ultimo_reajuste' => 'date:Y-m-d',
        'data_vencimento'      => 'date:Y-m-d',
        'notify_emails'        => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function valueChanges(): HasMany
    {
        return $this->hasMany(ManualReajusteValueChange::class);
    }
}
