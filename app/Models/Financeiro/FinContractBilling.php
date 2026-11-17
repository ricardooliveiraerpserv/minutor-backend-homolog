<?php

namespace App\Models\Financeiro;

use App\Models\Contract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Regras de faturamento de um contrato (aba Faturamento). Uma linha por contrato. */
class FinContractBilling extends Model
{
    protected $table = 'fin_contract_billings';

    protected $fillable = [
        'contract_id', 'company_id', 'ativo', 'regra_recorrente', 'valor_fixo', 'periodicidade',
        'inicio_competencia', 'fim_competencia', 'documento', 'codigo_tributacao_nacional', 'discriminacao',
        'dia_faturamento', 'vencimento_dias', 'bank_account_id', 'chart_account_id', 'cost_center_id',
        'reter_federais', 'aliquota_irrf', 'aliquota_csll', 'aliquota_pis', 'aliquota_cofins', 'aliquota_iss',
        'iss_retido', 'observacoes', 'updated_by',
    ];

    protected $casts = [
        'ativo' => 'boolean', 'reter_federais' => 'boolean', 'iss_retido' => 'boolean',
        'valor_fixo' => 'decimal:2', 'dia_faturamento' => 'integer', 'vencimento_dias' => 'integer',
        'aliquota_irrf' => 'decimal:4', 'aliquota_csll' => 'decimal:4', 'aliquota_pis' => 'decimal:4',
        'aliquota_cofins' => 'decimal:4', 'aliquota_iss' => 'decimal:4',
    ];

    protected $attributes = [
        'ativo' => true, 'regra_recorrente' => 'none', 'periodicidade' => 'mensal', 'documento' => 'nfse',
        'dia_faturamento' => 1, 'vencimento_dias' => 10, 'reter_federais' => true, 'iss_retido' => false,
    ];

    public const REGRAS = ['none', 'mensalidade', 'banco_horas', 'valor_fixo'];
    public const PERIODICIDADES = ['mensal' => 1, 'bimestral' => 2, 'trimestral' => 3, 'semestral' => 6, 'anual' => 12];
    public const DOCUMENTOS = ['nfse', 'nota_debito', 'nenhum'];

    public function contract(): BelongsTo { return $this->belongsTo(Contract::class); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(FinBankAccount::class, 'bank_account_id'); }
    public function chartAccount(): BelongsTo { return $this->belongsTo(FinChartAccount::class, 'chart_account_id'); }

    /** Config salva ou um objeto com os defaults (não persistido) para o contrato. */
    public static function paraContrato(Contract $c): self
    {
        return static::firstWhere('contract_id', $c->id)
            ?? new static(['contract_id' => $c->id, 'company_id' => $c->company_id, 'regra_recorrente' => static::regraSugerida($c)]);
    }

    /** Sugestão de recorrência pelo tipo de contrato (contract_types.code). */
    public static function regraSugerida(Contract $c): string
    {
        $code = strtolower((string) ($c->contractType->code ?? ''));
        return match (true) {
            in_array($code, ['cloud', 'saas'], true) => 'mensalidade',
            // Banco de Horas FIXO é pacote comprado de uma vez (parcela avulsa), não mensalidade.
            $code === 'monthly_hours' => 'banco_horas',
            default => 'none',
        };
    }

    /** A competência YYYY-MM cai num mês de cobrança, pela periodicidade a partir do início? */
    public function competenciaCobrada(string $ym): bool
    {
        if ($this->inicio_competencia && $ym < $this->inicio_competencia) return false;
        if ($this->fim_competencia && $ym > $this->fim_competencia) return false;
        $passo = self::PERIODICIDADES[$this->periodicidade] ?? 1;
        if ($passo === 1 || ! $this->inicio_competencia) return true;
        [$ya, $ma] = array_map('intval', explode('-', $this->inicio_competencia));
        [$yb, $mb] = array_map('intval', explode('-', $ym));
        return ((($yb - $ya) * 12 + ($mb - $ma)) % $passo) === 0;
    }
}
