<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Adiantamento extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $table = 'adiantamentos';

    // beneficiario_tipo
    public const TIPO_CONSULTOR = 'consultor';
    public const TIPO_PARCEIRO  = 'parceiro';

    // tipo (natureza no fechamento)
    //  - ADIANTAMENTO: desconta no fechamento (via parcelas).
    //  - EMPRESTIMO  : soma o valor_total no mês em que foi feito (data_realizado)
    //                  e é quitado pelas parcelas (descontos) nos meses seguintes.
    public const NATUREZA_ADIANTAMENTO = 'adiantamento';
    public const NATUREZA_EMPRESTIMO   = 'emprestimo';

    protected $fillable = [
        'beneficiario_tipo',
        'beneficiario_id',
        'tipo',
        'valor_total',
        'data_realizado',
        'disponibilizado',
        'num_parcelas',
        'primeira_competencia',
        'descricao',
        'created_by',
    ];

    protected $casts = [
        'valor_total'     => 'decimal:2',
        'num_parcelas'    => 'integer',
        'data_realizado'  => 'date',
        'disponibilizado' => 'boolean',
    ];

    public function parcelas(): HasMany
    {
        return $this->hasMany(AdiantamentoParcela::class)->orderBy('numero');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Nome do beneficiário (consultor = User; parceiro = Partner). */
    public function beneficiarioNome(): ?string
    {
        if ($this->beneficiario_tipo === self::TIPO_PARCEIRO) {
            return Partner::find($this->beneficiario_id)?->name;
        }
        return User::find($this->beneficiario_id)?->name;
    }

    /**
     * Filtro do adiantamento pai: beneficiário + exclui empréstimo ainda NÃO
     * disponibilizado (que fica inerte no fechamento — sem descontar as parcelas).
     */
    private static function whereBeneficiarioAtivo($q, string $tipo, int $beneficiarioId)
    {
        return $q->where('beneficiario_tipo', $tipo)
            ->where('beneficiario_id', $beneficiarioId)
            ->where(fn ($w) => $w
                ->where('tipo', '!=', self::NATUREZA_EMPRESTIMO)
                ->orWhere('disponibilizado', true));
    }

    /**
     * REGIME DE CAIXA — mês da parcela DESCONTADA no fechamento da competência $yearMonth.
     * O fechamento da competência M é pago em M+1, e a parcela é rotulada pelo mês em que
     * o dinheiro efetivamente sai (caixa). Logo o fechamento da competência M desconta a
     * parcela cujo mês de caixa = M+1. Ex.: fechamento de julho desconta a parcela de
     * agosto (recebida em agosto, quando a folha de julho é paga) → mostra "04/24" p/ um
     * adiantamento que começou a descontar em maio. Aritmética pura no "AAAA-MM" pra evitar
     * pegadinhas de dia/timezone do Carbon.
     */
    private static function parcelaYmNoFechamento(string $yearMonth): string
    {
        [$y, $m] = array_map('intval', explode('-', $yearMonth));
        $m++;
        if ($m > 12) { $m = 1; $y++; }
        return sprintf('%04d-%02d', $y, $m);
    }

    /**
     * Total de adiantamento a descontar de um beneficiário numa competência
     * (soma das parcelas de TODOS os adiantamentos dele que caem no mês —
     * empréstimo ainda não disponibilizado não conta).
     * Usado pelos fechamentos de consultor e parceiro.
     */
    public static function descontoNoMes(string $tipo, int $beneficiarioId, string $yearMonth): float
    {
        return round((float) AdiantamentoParcela::query()
            ->where('year_month', self::parcelaYmNoFechamento($yearMonth))
            ->whereHas('adiantamento', fn ($q) => self::whereBeneficiarioAtivo($q, $tipo, $beneficiarioId))
            ->sum('valor'), 2);
    }

    /**
     * Total de EMPRÉSTIMO a SOMAR no fechamento de um beneficiário na competência
     * = soma do valor_total dos empréstimos cujo `data_realizado` cai no mês.
     * É o aporte (dinheiro entregue via fechamento); as parcelas quitam depois.
     */
    public static function aporteEmprestimoNoMes(string $tipo, int $beneficiarioId, string $yearMonth): float
    {
        return round((float) static::query()
            ->where('tipo', self::NATUREZA_EMPRESTIMO)
            ->where('disponibilizado', true)
            ->where('beneficiario_tipo', $tipo)
            ->where('beneficiario_id', $beneficiarioId)
            ->whereYear('data_realizado', (int) substr($yearMonth, 0, 4))
            ->whereMonth('data_realizado', (int) substr($yearMonth, 5, 2))
            ->sum('valor_total'), 2);
    }

    /**
     * UMA linha por empréstimo cujo aporte cai na competência (não somadas) —
     * exibida no fechamento como crédito verde ("+"). Item:
     * ['adiantamento_id'=>int, 'legenda'=>?string, 'valor'=>float].
     */
    public static function aportesEmprestimoNoMes(string $tipo, int $beneficiarioId, string $yearMonth): array
    {
        return static::query()
            ->where('tipo', self::NATUREZA_EMPRESTIMO)
            ->where('disponibilizado', true)
            ->where('beneficiario_tipo', $tipo)
            ->where('beneficiario_id', $beneficiarioId)
            ->whereYear('data_realizado', (int) substr($yearMonth, 0, 4))
            ->whereMonth('data_realizado', (int) substr($yearMonth, 5, 2))
            ->orderBy('id')
            ->get(['id', 'descricao', 'valor_total', 'num_parcelas'])
            ->map(fn ($e) => [
                'adiantamento_id' => $e->id,
                'legenda'         => implode(' · ', array_filter([
                    $e->descricao,
                    'empréstimo' . ($e->num_parcelas > 1 ? " ({$e->num_parcelas}x)" : ''),
                ])),
                'valor'           => round((float) $e->valor_total, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Descrição(ões) dos adiantamentos com parcela na competência — exibida no
     * fechamento ao lado do valor de adiantamento. Junta múltiplos com " · ".
     */
    public static function descricaoNoMes(string $tipo, int $beneficiarioId, string $yearMonth): ?string
    {
        $descs = self::whereBeneficiarioAtivo(static::query(), $tipo, $beneficiarioId)
            ->whereHas('parcelas', fn ($q) => $q->where('year_month', self::parcelaYmNoFechamento($yearMonth)))
            ->pluck('descricao')
            ->filter()
            ->unique()
            ->values();

        return $descs->isEmpty() ? null : $descs->implode(' · ');
    }

    /**
     * Legenda da(s) parcela(s) da competência no formato NN/TT (ex.: "01/24").
     * Junta múltiplos adiantamentos do mês com " · ".
     */
    public static function parcelaLabelNoMes(string $tipo, int $beneficiarioId, string $yearMonth): ?string
    {
        $rows = AdiantamentoParcela::query()
            ->where('year_month', self::parcelaYmNoFechamento($yearMonth))
            ->whereHas('adiantamento', fn ($q) => self::whereBeneficiarioAtivo($q, $tipo, $beneficiarioId))
            ->with('adiantamento:id,num_parcelas')
            ->orderBy('numero')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->map(fn ($p) => str_pad((string) $p->numero, 2, '0', STR_PAD_LEFT)
            . '/' . str_pad((string) ($p->adiantamento?->num_parcelas ?? 0), 2, '0', STR_PAD_LEFT))
            ->implode(' · ');
    }

    /**
     * UMA entrada por parcela de adiantamento do beneficiário na competência —
     * cada adiantamento vira uma linha separada no fechamento (não somadas).
     * Item: ['adiantamento_id'=>int, 'descricao'=>?string, 'parcela'=>'NN/TT',
     *        'valor'=>float, 'legenda'=>'descricao · NN/TT'].
     */
    public static function parcelasNoMes(string $tipo, int $beneficiarioId, string $yearMonth): array
    {
        return AdiantamentoParcela::query()
            ->where('year_month', self::parcelaYmNoFechamento($yearMonth))
            ->whereHas('adiantamento', fn ($q) => self::whereBeneficiarioAtivo($q, $tipo, $beneficiarioId))
            ->with('adiantamento:id,num_parcelas,descricao')
            ->orderBy('numero')
            ->get()
            ->map(function ($p) {
                $parcela = str_pad((string) $p->numero, 2, '0', STR_PAD_LEFT)
                    . '/' . str_pad((string) ($p->adiantamento?->num_parcelas ?? 0), 2, '0', STR_PAD_LEFT);
                $desc = $p->adiantamento?->descricao;
                return [
                    'adiantamento_id' => $p->adiantamento_id,
                    'descricao'       => $desc,
                    'parcela'         => $parcela,
                    'valor'           => round((float) $p->valor, 2),
                    'legenda'         => implode(' · ', array_filter([$desc, $parcela])),
                ];
            })
            ->values()
            ->all();
    }
}
