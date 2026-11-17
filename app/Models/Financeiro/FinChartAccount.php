<?php

namespace App\Models\Financeiro;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Plano de contas por empresa; `grupo_dre` posiciona a conta nas linhas da DRE. */
class FinChartAccount extends Model
{
    use BelongsToCompany;

    protected $table = 'fin_chart_accounts';

    protected $fillable = [
        'company_id', 'parent_id', 'codigo', 'nome', 'natureza', 'grupo_dre', 'aceita_lancamento', 'ativo',
    ];

    protected $casts = ['aceita_lancamento' => 'boolean', 'ativo' => 'boolean'];

    public const NATUREZAS = ['receita', 'despesa'];
    public const GRUPOS_DRE = [
        'receita_bruta', 'deducoes', 'custo_servicos', 'despesas_operacionais',
        'resultado_financeiro', 'impostos_lucro',
    ];

    /**
     * Estrutura inicial sugerida (empresa de serviços de TI). Códigos hierárquicos;
     * as contas-grupo (1, 2…) não aceitam lançamento.
     */
    public const PADRAO = [
        ['1', 'Receitas', 'receita', null, false],
        ['1.01', 'Receita de serviços – recorrentes', 'receita', 'receita_bruta', true],
        ['1.02', 'Receita de serviços – On Demand e excedente', 'receita', 'receita_bruta', true],
        ['1.03', 'Receita de licenças', 'receita', 'receita_bruta', true],
        ['1.04', 'Reembolso de despesas (nota de débito)', 'receita', 'receita_bruta', true],
        ['1.05', 'Receitas financeiras', 'receita', 'resultado_financeiro', true],
        ['2', 'Deduções da receita', 'despesa', null, false],
        ['2.01', 'ISS', 'despesa', 'deducoes', true],
        ['2.02', 'PIS', 'despesa', 'deducoes', true],
        ['2.03', 'COFINS', 'despesa', 'deducoes', true],
        ['3', 'Custo dos serviços', 'despesa', null, false],
        ['3.01', 'Consultores (cooperativa)', 'despesa', 'custo_servicos', true],
        ['3.02', 'Parceiros', 'despesa', 'custo_servicos', true],
        ['3.03', 'Taxa administrativa da cooperativa', 'despesa', 'custo_servicos', true],
        ['3.04', 'Despesas de viagem reembolsáveis', 'despesa', 'custo_servicos', true],
        ['4', 'Despesas operacionais', 'despesa', null, false],
        ['4.01', 'Diretoria', 'despesa', 'despesas_operacionais', true],
        ['4.02', 'Folha e benefícios', 'despesa', 'despesas_operacionais', true],
        ['4.03', 'Software e telecom', 'despesa', 'despesas_operacionais', true],
        ['4.04', 'Contabilidade e serviços de terceiros', 'despesa', 'despesas_operacionais', true],
        ['4.05', 'Tarifas bancárias', 'despesa', 'resultado_financeiro', true],
        ['5', 'Impostos sobre o lucro', 'despesa', null, false],
        ['5.01', 'IRPJ', 'despesa', 'impostos_lucro', true],
        ['5.02', 'CSLL', 'despesa', 'impostos_lucro', true],
    ];

    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_id'); }
}
