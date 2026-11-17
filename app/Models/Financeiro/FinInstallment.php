<?php

namespace App\Models\Financeiro;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parcela de faturamento: o que será faturado ao cliente numa competência. Nasce do contrato
 * (recorrente), do fechamento On Demand, de excedente cobrado, de despesas cobráveis ou é
 * lançada à mão (avulsa). Na F3 vira NFS-e / nota de débito; na F4, título a receber.
 * Parcela `processada` é imutável.
 */
class FinInstallment extends Model
{
    use BelongsToCompany;

    protected $table = 'fin_installments';

    protected $fillable = [
        'company_id', 'contract_id', 'customer_id', 'project_id', 'competencia', 'origem', 'origem_ref',
        'documento', 'descricao', 'horas', 'valor_bruto', 'desconto', 'ret_irrf', 'ret_csll', 'ret_pis',
        'ret_cofins', 'ret_iss', 'valor_iss', 'valor_liquido', 'vencimento', 'status', 'detalhes',
        'motivo_cancelamento', 'processada_em', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'horas' => 'decimal:2', 'valor_bruto' => 'decimal:2', 'desconto' => 'decimal:2',
        'ret_irrf' => 'decimal:2', 'ret_csll' => 'decimal:2', 'ret_pis' => 'decimal:2', 'ret_cofins' => 'decimal:2',
        'ret_iss' => 'decimal:2', 'valor_iss' => 'decimal:2', 'valor_liquido' => 'decimal:2',
        'vencimento' => 'date', 'detalhes' => 'array', 'processada_em' => 'datetime',
    ];

    public const ORIGENS = ['recorrente', 'on_demand', 'excedente', 'despesa', 'avulsa'];
    public const STATUS = ['prevista', 'pronta', 'processada', 'cancelada'];

    public function contract(): BelongsTo { return $this->belongsTo(Contract::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }

    public function editavel(): bool
    {
        return in_array($this->status, ['prevista', 'pronta'], true);
    }

    public function getTotalRetencoesAttribute(): float
    {
        return round((float) $this->ret_irrf + (float) $this->ret_csll + (float) $this->ret_pis
            + (float) $this->ret_cofins + (float) $this->ret_iss, 2);
    }
}
