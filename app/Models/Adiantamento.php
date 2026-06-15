<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Adiantamento extends Model
{
    protected $table = 'adiantamentos';

    public const TIPO_CONSULTOR = 'consultor';
    public const TIPO_PARCEIRO  = 'parceiro';

    protected $fillable = [
        'beneficiario_tipo',
        'beneficiario_id',
        'valor_total',
        'num_parcelas',
        'primeira_competencia',
        'descricao',
        'created_by',
    ];

    protected $casts = [
        'valor_total'  => 'decimal:2',
        'num_parcelas' => 'integer',
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
     * Total de adiantamento a descontar de um beneficiário numa competência
     * (soma das parcelas de TODOS os adiantamentos dele que caem no mês).
     * Usado pelos fechamentos de consultor e parceiro.
     */
    public static function descontoNoMes(string $tipo, int $beneficiarioId, string $yearMonth): float
    {
        return round((float) AdiantamentoParcela::query()
            ->where('year_month', $yearMonth)
            ->whereHas('adiantamento', fn ($q) => $q
                ->where('beneficiario_tipo', $tipo)
                ->where('beneficiario_id', $beneficiarioId))
            ->sum('valor'), 2);
    }

    /**
     * Descrição(ões) dos adiantamentos com parcela na competência — exibida no
     * fechamento ao lado do valor de adiantamento. Junta múltiplos com " · ".
     */
    public static function descricaoNoMes(string $tipo, int $beneficiarioId, string $yearMonth): ?string
    {
        $descs = static::query()
            ->where('beneficiario_tipo', $tipo)
            ->where('beneficiario_id', $beneficiarioId)
            ->whereHas('parcelas', fn ($q) => $q->where('year_month', $yearMonth))
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
            ->where('year_month', $yearMonth)
            ->whereHas('adiantamento', fn ($q) => $q
                ->where('beneficiario_tipo', $tipo)
                ->where('beneficiario_id', $beneficiarioId))
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
            ->where('year_month', $yearMonth)
            ->whereHas('adiantamento', fn ($q) => $q
                ->where('beneficiario_tipo', $tipo)
                ->where('beneficiario_id', $beneficiarioId))
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
