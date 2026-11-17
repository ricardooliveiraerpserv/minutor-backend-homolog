<?php

namespace App\Models\Financeiro;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Contract;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Documento fiscal emitido de uma parcela: NFS-e ou Nota de Débito. */
class FinInvoice extends Model
{
    use BelongsToCompany;

    protected $table = 'fin_invoices';

    protected $fillable = [
        'company_id', 'installment_id', 'contract_id', 'customer_id', 'tipo', 'emissor', 'ambiente', 'status',
        'serie', 'numero_dps', 'numero', 'chave_acesso', 'codigo_verificacao', 'competencia', 'data_emissao',
        'valor_servico', 'desconto', 'valor_iss', 'total_retencoes', 'valor_liquido', 'discriminacao', 'tomador',
        'mensagens', 'xml_dps', 'xml_nfse', 'emitida_em', 'cancelada_em', 'motivo_cancelamento', 'emitida_por',
    ];

    protected $hidden = ['xml_dps', 'xml_nfse'];

    protected $casts = [
        'tomador' => 'array', 'mensagens' => 'array', 'data_emissao' => 'date',
        'emitida_em' => 'datetime', 'cancelada_em' => 'datetime', 'numero_dps' => 'integer',
        'valor_servico' => 'decimal:2', 'desconto' => 'decimal:2', 'valor_iss' => 'decimal:2',
        'total_retencoes' => 'decimal:2', 'valor_liquido' => 'decimal:2',
    ];

    public function installment(): BelongsTo { return $this->belongsTo(FinInstallment::class); }
    public function contract(): BelongsTo { return $this->belongsTo(Contract::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }

    /** Nota que ainda "ocupa" a parcela (impede emitir de novo). */
    public function viva(): bool
    {
        return in_array($this->status, ['enviando', 'autorizada'], true);
    }
}
