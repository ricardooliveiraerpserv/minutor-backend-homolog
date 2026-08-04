<?php

namespace App\Services;

use App\Models\ContractHourMultiplier;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;

/**
 * PONTO ÚNICO DE VERDADE do multiplicador de horas faturáveis ao cliente.
 *
 * REGRA DE OURO: só o LADO CLIENTE/RECEITA chama este serviço. As somas do lado
 * operacional (consultor/parceiro) — apontamento, fechamento, banco de horas,
 * pagamento e CUSTO da rentabilidade — leem o timesheet REAL e NUNCA passam por aqui.
 * As horas a mais têm custo ZERO (só inflam receita/faturamento do cliente).
 *
 * Uso:
 *   $svc->factorForContract($contractId, $date)   → 1.0 (sem regra) | 1+%/100
 *   $svc->applyMinutes($contractId, $date, $min)   → minutos já multiplicados
 *   $svc->factorForProject($projectId, $date)      → resolve contrato do projeto
 *
 * Cache por request (memoização) pra não repetir query nas somas de fechamento.
 */
class ContractHourMultiplierService
{
    /** cache: "contractId|Y-m-d" => float */
    private array $factorCache = [];
    /** cache: projectId => ?contractId */
    private array $projectContract = [];
    /** cache: projectId => bool (projeto do tipo Fechado) */
    private array $closedCache = [];

    private static function d($date): string
    {
        return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10);
    }

    /** Fator do contrato numa data. 1.0 = sem regra ativa vigente (sem efeito). */
    public function factorForContract(?int $contractId, $date): float
    {
        if (!$contractId) return 1.0;
        $day = self::d($date);
        $key = $contractId . '|' . $day;
        if (array_key_exists($key, $this->factorCache)) {
            return $this->factorCache[$key];
        }
        $rule = ContractHourMultiplier::query()
            ->where('contract_id', $contractId)
            ->effectiveOn($day)
            ->first();
        $factor = $rule ? $rule->factor() : 1.0;
        return $this->factorCache[$key] = $factor;
    }

    /** Minutos apontados → minutos faturáveis ao cliente (já multiplicados). */
    public function applyMinutes(?int $contractId, $date, int|float $minutes): float
    {
        return ((float) $minutes) * $this->factorForContract($contractId, $date);
    }

    /** Resolve o contrato do projeto (contracts.project_id) e devolve o fator. */
    public function factorForProject(?int $projectId, $date): float
    {
        return $this->factorForContract($this->contractIdOfProject($projectId), $date);
    }

    /** % da regra ativa vigente do contrato numa data (null = sem regra → sem efeito). */
    public function rulePercentFor(?int $contractId, $date): ?float
    {
        if (!$contractId) return null;
        $rule = ContractHourMultiplier::query()
            ->where('contract_id', $contractId)
            ->effectiveOn(self::d($date))
            ->first();
        return $rule ? (float) $rule->percent : null;
    }

    /** % derivado pro timesheet (resolve contrato do projeto). Alimenta contract_client_pct. */
    public function pctForTimesheet(?int $projectId, $date): ?float
    {
        // Projeto FECHADO nunca multiplica — nem o excedente (o excedente lê o mesmo
        // contract_client_pct do apontamento; deixando null aqui, exclui em todas as rotinas).
        if ($this->isClosedProject($projectId)) return null;
        return $this->rulePercentFor($this->contractIdOfProject($projectId), $date);
    }

    /** Projeto do tipo "Fechado" (escopo/valor fixo) — EXCLUÍDO do multiplicador. */
    public function isClosedProject(?int $projectId): bool
    {
        if (!$projectId) return false;
        if (array_key_exists($projectId, $this->closedCache)) {
            return $this->closedCache[$projectId];
        }
        $p = \App\Models\Project::query()->with('contractType:id,code,name')->find($projectId);
        return $this->closedCache[$projectId] = ($p?->isClosedContract() ?? false);
    }

    /** Dentre os project ids dados, os que são do tipo Fechado (1 query). */
    private function closedProjectIds(array $projectIds): array
    {
        if (!$projectIds) return [];
        return \App\Models\Project::query()
            ->whereIn('id', $projectIds)
            ->whereHas('contractType', fn ($q) => $q->where(fn ($w) => $w->where('code', 'closed')->orWhereRaw('LOWER(name) = ?', ['fechado'])))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** Projetos governados por um contrato: o projeto do contrato + descendentes (BFS). */
    public function projectIdsOfContract(int $contractId): array
    {
        $root = Contract::query()->whereKey($contractId)->value('project_id');
        if (!$root) return [];
        $ids = [(int) $root];
        $frontier = [(int) $root];
        for ($lvl = 0; $lvl < 10 && $frontier; $lvl++) {
            $children = \App\Models\Project::query()
                ->whereIn('parent_project_id', $frontier)
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
            $children = array_values(array_diff($children, $ids));
            if (!$children) break;
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }
        return $ids;
    }

    /**
     * Recalcula timesheets.contract_client_pct de TODOS os timesheets do contrato.
     * Dentro da vigência da regra ativa → percent; fora / sem regra → NULL.
     * Chamar ao criar/editar/remover regra.
     */
    public function recomputeContract(int $contractId): void
    {
        $projectIds = $this->projectIdsOfContract($contractId);
        if (!$projectIds) return;

        // Projetos FECHADOS ficam de fora do multiplicador (nem excedente): pct sempre NULL.
        $closed = $this->closedProjectIds($projectIds);
        if ($closed) {
            \App\Models\Timesheet::query()->whereIn('project_id', $closed)
                ->whereNotNull('contract_client_pct')->update(['contract_client_pct' => null]);
        }
        $openIds = array_values(array_diff($projectIds, $closed));
        if (!$openIds) return;

        $rule = ContractHourMultiplier::query()
            ->where('contract_id', $contractId)->where('active', true)->first();

        $base = \App\Models\Timesheet::query()->whereIn('project_id', $openIds);

        if (!$rule) {
            (clone $base)->whereNotNull('contract_client_pct')->update(['contract_client_pct' => null]);
            return;
        }

        $start = $rule->start_date?->format('Y-m-d');
        $end   = $rule->end_date?->format('Y-m-d');

        // Dentro da vigência → percent.
        (clone $base)
            ->when($start, fn ($q) => $q->whereDate('date', '>=', $start))
            ->when($end,   fn ($q) => $q->whereDate('date', '<=', $end))
            ->update(['contract_client_pct' => (float) $rule->percent]);

        // Fora da vigência → limpa (regra pode ter mudado de período).
        (clone $base)
            ->where(function ($q) use ($start, $end) {
                if ($start) $q->orWhereDate('date', '<', $start);
                if ($end)   $q->orWhereDate('date', '>', $end);
            })
            ->whereNotNull('contract_client_pct')
            ->update(['contract_client_pct' => null]);
    }

    public function contractIdOfProject(?int $projectId): ?int
    {
        if (!$projectId) return null;
        if (array_key_exists($projectId, $this->projectContract)) {
            return $this->projectContract[$projectId];
        }
        // On Demand aponta em projeto FILHO, mas o contrato (e a regra) fica no RAIZ.
        // Sobe a cadeia parent_project_id até achar um contrato (teto de 10 níveis).
        $pid = $projectId; $seen = [];
        for ($i = 0; $i < 10 && $pid && !in_array($pid, $seen, true); $i++) {
            $seen[] = $pid;
            $cid = Contract::query()->where('project_id', $pid)->orderByDesc('id')->value('id');
            if ($cid) {
                return $this->projectContract[$projectId] = (int) $cid;
            }
            $pid = \App\Models\Project::query()->where('id', $pid)->value('parent_project_id');
        }
        return $this->projectContract[$projectId] = null;
    }
}
