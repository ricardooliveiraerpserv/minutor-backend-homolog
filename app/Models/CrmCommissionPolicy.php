<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Política de Comissão: regra condicional de percentual. Resolução por prioridade —
 * a 1ª regra ativa cujas condições casam define o %. Sem regra → fallback (% por vendedor).
 */
class CrmCommissionPolicy extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'active', 'priority',
        'cargo', 'pipeline_id', 'tipo_negocio', 'min_valor', 'max_valor', 'min_margem', 'max_margem',
        'min_atingimento', 'max_atingimento', 'percentual',
    ];
    protected $casts = [
        'active' => 'boolean', 'priority' => 'integer',
        'min_valor' => 'decimal:2', 'max_valor' => 'decimal:2',
        'min_margem' => 'decimal:2', 'max_margem' => 'decimal:2',
        'min_atingimento' => 'decimal:2', 'max_atingimento' => 'decimal:2', 'percentual' => 'decimal:2',
    ];

    /**
     * Resolve o percentual para um negócio. Retorna [percentual, nomeDaRegra|null].
     * $ctx: cargo, pipeline_id, valor, margem, tipo, atingimento (todos opcionais/null).
     * $fallback = % por vendedor / padrão da empresa quando nenhuma regra casa.
     */
    public static function resolve(array $ctx, float $fallback): array
    {
        $cargo = $ctx['cargo'] ?? null;
        $pipelineId = $ctx['pipeline_id'] ?? null;
        $valor = (float) ($ctx['valor'] ?? 0);
        $margem = $ctx['margem'] ?? null;
        $tipo = $ctx['tipo'] ?? null;
        $ating = $ctx['atingimento'] ?? null;

        foreach (static::where('active', true)->orderBy('priority')->orderBy('id')->get() as $p) {
            if ($p->cargo && $p->cargo !== $cargo) continue;
            if ($p->pipeline_id && (int) $p->pipeline_id !== (int) $pipelineId) continue;
            if ($p->tipo_negocio && $p->tipo_negocio !== $tipo) continue;
            if ($p->min_valor !== null && $valor < (float) $p->min_valor) continue;
            if ($p->max_valor !== null && $valor > (float) $p->max_valor) continue;
            if ($p->min_margem !== null && ($margem === null || $margem < (float) $p->min_margem)) continue;
            if ($p->max_margem !== null && ($margem === null || $margem > (float) $p->max_margem)) continue;
            if ($p->min_atingimento !== null && ($ating === null || $ating < (float) $p->min_atingimento)) continue;
            if ($p->max_atingimento !== null && ($ating === null || $ating > (float) $p->max_atingimento)) continue;
            return [(float) $p->percentual, $p->name];
        }
        return [$fallback, null];
    }
}
