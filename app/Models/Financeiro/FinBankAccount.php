<?php

namespace App\Models\Financeiro;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Conta bancária da empresa. `gateway` diz qual adaptador de cobrança emite boleto/PIX
 * por esta conta; as credenciais do gateway ficam cifradas e ocultas.
 */
class FinBankAccount extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'fin_bank_accounts';

    protected $fillable = [
        'company_id', 'nome', 'banco_codigo', 'agencia', 'conta', 'tipo', 'gateway',
        'saldo_inicial', 'saldo_inicial_data', 'ativo',
    ];

    protected $hidden = ['gateway_credenciais'];

    protected $casts = [
        'gateway_credenciais' => 'encrypted:array',
        'saldo_inicial' => 'decimal:2',
        'saldo_inicial_data' => 'date',
        'ativo' => 'boolean',
    ];

    protected $appends = ['tem_credenciais'];

    public const TIPOS = ['corrente', 'cobranca', 'aplicacao', 'caixa'];
    public const GATEWAYS = ['none', 'fake', 'inter', 'cnab_caixa'];

    public function getTemCredenciaisAttribute(): bool
    {
        return ! empty($this->attributes['gateway_credenciais']);
    }
}
