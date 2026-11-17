<?php

namespace App\Services\Financeiro;

use App\Models\Contract;
use App\Models\ExcessHourCharge;
use App\Models\Expense;
use App\Models\FechamentoCliente;
use App\Models\Financeiro\FinCompanySetting;
use App\Models\Financeiro\FinContractBilling;
use App\Models\Financeiro\FinInstallment;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Gera as parcelas de UMA empresa numa competência (YYYY-MM), a partir de:
 *  - recorrente: contratos com regra de recorrência (mensalidade / banco de horas / valor fixo);
 *  - on_demand: fechamentos de cliente ENCERRADOS (snapshot por projeto, já com crédito abatido);
 *  - despesa:   despesas cobráveis do snapshot do fechamento → nota de débito por contrato;
 *  - excedente: excess_hour_charges com status "cobrado".
 * Idempotente: chave (contract_id, competencia, origem, origem_ref). Parcela processada/cancelada
 * nunca é alterada. Valores e retenções são recalculados enquanto a parcela está prevista/pronta.
 */
class ParcelaGenerator
{
    private array $empresas = [];
    private array $contratoPorProjeto = [];

    public function __construct(private readonly RetencoesCalculator $retencoes) {}

    /** @return array{criadas:int,atualizadas:int,inalteradas:int,travadas:int,sem_contrato:array<int,string>} */
    public function gerar(int $companyId, string $ym, ?int $userId = null): array
    {
        $this->validarCompetencia($ym);
        $res = ['criadas' => 0, 'atualizadas' => 0, 'inalteradas' => 0, 'travadas' => 0, 'sem_contrato' => []];

        foreach ($this->recorrentes($companyId, $ym) as $p) $this->gravar($p, $userId, $res);
        foreach ($this->doFechamento($companyId, $ym, $res) as $p) $this->gravar($p, $userId, $res);
        foreach ($this->excedentes($companyId, $ym, $res) as $p) $this->gravar($p, $userId, $res);

        $res['sem_contrato'] = array_values(array_unique($res['sem_contrato']));
        return $res;
    }

    /** Prévia (sem gravar) das recorrentes de um contrato nos próximos N meses. */
    public function simularContrato(Contract $c, string $desdeYm, int $meses = 12): array
    {
        $cfg = FinContractBilling::paraContrato($c);
        $out = [];
        $ym = $desdeYm;
        for ($i = 0; $i < $meses; $i++) {
            $p = $this->parcelaRecorrente($c, $cfg, $ym);
            if ($p) $out[] = $p;
            $ym = Carbon::createFromFormat('Y-m-d', $ym . '-01')->addMonth()->format('Y-m');
        }
        return $out;
    }

    // ─── Fontes ───────────────────────────────────────────────────────────────

    private function recorrentes(int $companyId, string $ym): Collection
    {
        return FinContractBilling::query()
            ->where('ativo', true)->where('regra_recorrente', '!=', 'none')
            ->whereHas('contract', fn ($q) => $q->withoutGlobalScopes()->where('company_id', $companyId))
            ->with(['contract' => fn ($q) => $q->withoutGlobalScopes()->with(['project', 'contractType'])])
            ->get()
            ->map(fn (FinContractBilling $cfg) => $this->parcelaRecorrente($cfg->contract, $cfg, $ym))
            ->filter();
    }

    private function parcelaRecorrente(Contract $c, FinContractBilling $cfg, string $ym): ?array
    {
        if ($cfg->regra_recorrente === 'none' || ! $cfg->ativo || ! $cfg->competenciaCobrada($ym)) {
            return null;
        }
        $proj = $c->project;
        $horas = null;
        $valor = match ($cfg->regra_recorrente) {
            'valor_fixo' => (float) $cfg->valor_fixo,
            'mensalidade' => $proj ? $proj->projectValueForCompetencia($ym) : (float) $c->valor_projeto,
            'banco_horas' => $proj
                ? round(($horas = $proj->soldHoursForCompetencia($ym)) * $proj->hourlyRateForCompetencia($ym), 2)
                : round(($horas = (float) $c->horas_contratadas) * (float) $c->valor_hora, 2),
            default => 0.0,
        };
        // Periodicidade > mensal: a parcela cobre N meses.
        $passo = FinContractBilling::PERIODICIDADES[$cfg->periodicidade] ?? 1;
        $valor = round($valor * $passo, 2);
        if ($valor <= 0) return null;

        return $this->montar($c, $cfg, $ym, 'recorrente', 'rec', $cfg->documento, $valor, 0.0, $horas !== null ? $horas * $passo : null,
            $this->rotuloRecorrente($cfg, $ym), ['regra' => $cfg->regra_recorrente, 'periodicidade' => $cfg->periodicidade]);
    }

    private function doFechamento(int $companyId, string $ym, array &$res): array
    {
        $out = [];
        $fechamentos = FechamentoCliente::withoutGlobalScopes()->where('year_month', $ym)->where('status', 'closed')->get();
        foreach ($fechamentos as $f) {
            // On Demand: um item por projeto do snapshot (crédito já abatido em total_receita).
            $projetos = collect($f->snapshot_contratos['projetos'] ?? [])->filter(fn ($p) => (float) ($p['total_receita'] ?? 0) > 0);
            $somaServicos = (float) $projetos->sum('total_receita');
            $descontoTotal = (float) ($f->desconto ?? 0);
            foreach ($projetos as $p) {
                $c = $this->contratoDoProjeto((int) $p['projeto_id']);
                if (! $c) { $res['sem_contrato'][] = 'On Demand ' . ($p['projeto_codigo'] ?? '') . ' ' . ($p['projeto_nome'] ?? ''); continue; }
                if ((int) $c->company_id !== $companyId) continue;
                $bruto = round((float) $p['total_receita'], 2);
                $desc = $somaServicos > 0 ? round($descontoTotal * $bruto / $somaServicos, 2) : 0.0;
                $cfg = FinContractBilling::paraContrato($c);
                $out[] = $this->montar($c, $cfg, $ym, 'on_demand', 'fc:' . $f->id . ':p:' . $p['projeto_id'], $cfg->documento, $bruto, $desc,
                    (float) ($p['horas_cobradas'] ?? $p['horas'] ?? 0),
                    'On Demand ' . $this->mesAno($ym) . ' — ' . ($p['horas_cobradas'] ?? $p['horas'] ?? 0) . ' h',
                    ['fechamento_cliente_id' => $f->id, 'projeto_id' => $p['projeto_id'], 'valor_hora' => $p['valor_hora'] ?? null,
                        'credito_abatido' => $p['credito_abatido'] ?? 0, 'desconto_fechamento' => $descontoTotal]);
            }

            // Despesas cobráveis → nota de débito agrupada por contrato.
            $despesas = collect($f->snapshot_despesas ?? []);
            if ($despesas->isEmpty()) continue;
            $projetoDaDespesa = Expense::withoutGlobalScopes()->whereIn('id', $despesas->pluck('id'))->pluck('project_id', 'id');
            $porContrato = [];
            foreach ($despesas as $d) {
                $pid = $projetoDaDespesa[$d['id']] ?? null;
                $c = $pid ? $this->contratoDoProjeto((int) $pid) : null;
                if (! $c) { $res['sem_contrato'][] = 'Despesa #' . $d['id'] . ' (' . ($d['projeto'] ?? '?') . ')'; continue; }
                if ((int) $c->company_id !== $companyId) continue;
                $porContrato[$c->id]['c'] = $c;
                $porContrato[$c->id]['itens'][] = ['id' => $d['id'], 'data' => $d['data'] ?? null, 'descricao' => $d['descricao'] ?? null, 'valor' => (float) $d['valor']];
            }
            foreach ($porContrato as $g) {
                $total = round(array_sum(array_column($g['itens'], 'valor')), 2);
                if ($total <= 0) continue;
                $cfg = FinContractBilling::paraContrato($g['c']);
                $out[] = $this->montar($g['c'], $cfg, $ym, 'despesa', 'fc:' . $f->id, 'nota_debito', $total, 0.0, null,
                    'Reembolso de despesas ' . $this->mesAno($ym) . ' (' . count($g['itens']) . ')',
                    ['fechamento_cliente_id' => $f->id, 'despesas' => $g['itens']]);
            }
        }
        return $out;
    }

    private function excedentes(int $companyId, string $ym, array &$res): array
    {
        $out = [];
        foreach (ExcessHourCharge::withoutGlobalScopes()->where('year_month', $ym)->where('status', 'cobrado')->get() as $e) {
            $c = $this->contratoDoProjeto((int) $e->project_id);
            if (! $c) { $res['sem_contrato'][] = 'Excedente projeto #' . $e->project_id; continue; }
            if ((int) $c->company_id !== $companyId) continue;
            $valor = round((float) $e->excess_value, 2);
            if ($valor <= 0) continue;
            $cfg = FinContractBilling::paraContrato($c);
            $out[] = $this->montar($c, $cfg, $ym, 'excedente', 'exc:' . $e->id, $cfg->documento, $valor, 0.0, (float) $e->excess_hours,
                'Horas excedentes ' . $this->mesAno($ym) . ' — ' . (float) $e->excess_hours . ' h',
                ['excess_hour_charge_id' => $e->id, 'valor_hora' => (float) $e->additional_hourly_rate, 'base' => $e->basis]);
        }
        return $out;
    }

    // ─── Montagem e gravação ─────────────────────────────────────────────────

    private function montar(Contract $c, FinContractBilling $cfg, string $ym, string $origem, string $ref, string $documento,
        float $bruto, float $desconto, ?float $horas, string $descricao, array $detalhes): array
    {
        $empresa = $this->empresa((int) $c->company_id);
        $calc = $this->retencoes->calcular($bruto, $desconto, $documento, $cfg, $empresa);
        $emissao = Carbon::createFromFormat('Y-m-d', $ym . '-01')->addMonth()->day(min(28, max(1, (int) $cfg->dia_faturamento)));
        $hoje = now()->format('Y-m');

        return [
            'chave' => ['contract_id' => $c->id, 'competencia' => $ym, 'origem' => $origem, 'origem_ref' => $ref],
            'dados' => [
                'company_id' => $c->company_id,
                'customer_id' => $c->customer_id,
                'project_id' => $c->project_id,
                'documento' => $documento,
                'descricao' => mb_substr($descricao, 0, 255),
                'horas' => $horas,
                'valor_bruto' => round($bruto, 2),
                'desconto' => round($desconto, 2),
                'vencimento' => $emissao->copy()->addDays((int) $cfg->vencimento_dias)->toDateString(),
                // recorrente de competência futura = prevista; o resto já tem valor definido = pronta
                'status' => ($origem === 'recorrente' && $ym > $hoje) ? 'prevista' : 'pronta',
                'detalhes' => $detalhes + ['emissao_prevista' => $emissao->toDateString()],
            ] + $calc,
        ];
    }

    private function gravar(array $p, ?int $userId, array &$res): void
    {
        $inst = FinInstallment::withoutGlobalScopes()->where($p['chave'])->first();
        if ($inst && ! $inst->editavel()) { $res['travadas']++; return; }

        if (! $inst) {
            FinInstallment::create($p['chave'] + $p['dados'] + ['created_by' => $userId]);
            $res['criadas']++;
            return;
        }
        // Edição manual de vencimento/descrição é preservada; valores seguem a fonte.
        $dados = $p['dados'];
        if (! empty($inst->detalhes['editado_manual'])) {
            unset($dados['vencimento'], $dados['descricao']);
            $dados['detalhes'] = $dados['detalhes'] + ['editado_manual' => true];
        }
        $inst->fill($dados);
        if ($inst->isDirty()) { $inst->updated_by = $userId; $inst->save(); $res['atualizadas']++; }
        else { $res['inalteradas']++; }
    }

    // ─── Apoio ───────────────────────────────────────────────────────────────

    /** Contrato de um projeto (ou do projeto-pai, p/ subprojetos On Demand). Prefere o ativo. */
    private function contratoDoProjeto(int $projectId): ?Contract
    {
        if (array_key_exists($projectId, $this->contratoPorProjeto)) return $this->contratoPorProjeto[$projectId];
        $busca = fn (int $pid) => Contract::withoutGlobalScopes()->with(['project', 'contractType'])
            ->where('project_id', $pid)->orderByRaw("CASE WHEN status = 'ativo' THEN 0 ELSE 1 END")->orderByDesc('id')->first();
        $c = $busca($projectId);
        if (! $c) {
            $pai = Project::withoutGlobalScopes()->whereKey($projectId)->value('parent_project_id');
            $c = $pai ? $busca((int) $pai) : null;
        }
        return $this->contratoPorProjeto[$projectId] = $c;
    }

    private function empresa(int $companyId): ?FinCompanySetting
    {
        return $this->empresas[$companyId] ??= FinCompanySetting::where('company_id', $companyId)->first();
    }

    private function rotuloRecorrente(FinContractBilling $cfg, string $ym): string
    {
        $tipo = ['mensalidade' => 'Mensalidade', 'banco_horas' => 'Banco de horas', 'valor_fixo' => 'Parcela fixa'][$cfg->regra_recorrente] ?? 'Recorrente';
        return $tipo . ' ' . $this->mesAno($ym) . ($cfg->periodicidade !== 'mensal' ? " ({$cfg->periodicidade})" : '');
    }

    private function mesAno(string $ym): string
    {
        return substr($ym, 5, 2) . '/' . substr($ym, 0, 4);
    }

    private function validarCompetencia(string $ym): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            throw new \InvalidArgumentException('Competência inválida (use AAAA-MM).');
        }
    }
}
