<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\CrmOpportunity;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Timesheet;
use Illuminate\Support\Carbon;

/**
 * Roadmap Fase 1 — Saúde da Conta. Calcula score + classificação + MOTIVOS (por que),
 * reusando os dados já existentes (contratos, horas, interação, follow-ups, renovação,
 * margem). NÃO altera nenhum módulo. "Projeto atrasado/crítico" depende do Cronograma
 * (ainda não em prod) → entra como ponto de integração futuro (peso 0 por enquanto).
 *
 * margem (opcional): quando informada (do financeiro Rentabilidade×Keruak), entra no cálculo;
 * margem < 0 = Crítico imediato. Sem margem, o fator é simplesmente não avaliado.
 */
class AccountHealthService
{
    public function compute(Customer $c, ?float $margem = null): array
    {
        $hoje = Carbon::today();
        $motivos = [];          // [{texto, peso}]
        $score = 0;
        $critico = false;
        $add = function (string $texto, int $peso) use (&$motivos, &$score) {
            $motivos[] = ['texto' => $texto, 'peso' => $peso];
            $score += $peso;
        };

        // ── Contratos: vencimento (tiered: pega o mais severo) ──
        $contratos = Contract::where('customer_id', $c->id)->where('status', 'ativo')->whereNotNull('data_vencimento')->get();
        if ($contratos->isNotEmpty()) {
            $minDias = $contratos->min(fn ($ct) => (int) $hoje->diffInDays(Carbon::parse($ct->data_vencimento), false));
            if ($minDias < 0) {
                $critico = true;
                $motivos[] = ['texto' => 'Contrato vencido', 'peso' => 0, 'critico' => true];
            } elseif ($minDias <= 30) {
                $add("Contrato vence em {$minDias} dias", 3);
            } elseif ($minDias <= 60) {
                $add("Contrato vence em {$minDias} dias", 2);
            } elseif ($minDias <= 90) {
                $add("Contrato vence em {$minDias} dias", 1);
            }
        }

        // ── Consumo de horas > 90% ──
        $projIds = Project::where('customer_id', $c->id)->whereNull('deleted_at')->pluck('id');
        $contratadas = (float) Project::whereIn('id', $projIds)->sum('sold_hours');
        if ($contratadas > 0) {
            $consumidas = (float) Timesheet::whereIn('project_id', $projIds)->whereNull('deleted_at')
                ->whereIn('status', ['approved', 'pending'])->sum('effort_minutes') / 60;
            $pct = $consumidas / $contratadas * 100;
            if ($pct > 90) $add('Consumo de horas em ' . round($pct) . '%', 2);
        }

        // ── Interação comercial (tiered) ──
        $ultima = collect([
            CrmOpportunity::where('customer_id', $c->id)->max('ultima_interacao_at'),
            $c->crmProfile?->ultima_interacao_at,
        ])->filter()->max();
        $baseInteracao = $ultima ?: $c->created_at;
        if ($baseInteracao) {
            $diasSemInteracao = (int) Carbon::parse($baseInteracao)->diffInDays($hoje);
            if ($diasSemInteracao >= 60) $add("Sem interação há {$diasSemInteracao} dias", 3);
            elseif ($diasSemInteracao >= 30) $add("Sem interação há {$diasSemInteracao} dias", 2);
            elseif ($diasSemInteracao >= 15) $add("Sem interação há {$diasSemInteracao} dias", 1);
        }

        // ── Follow-up vencido ──
        $oppIds = CrmOpportunity::where('customer_id', $c->id)->pluck('id');
        if ($oppIds->isNotEmpty()) {
            $fuVencido = CrmTask::whereIn('opportunity_id', $oppIds)->whereNull('concluida_at')
                ->whereNotNull('data')->whereDate('data', '<', $hoje)->exists();
            if ($fuVencido) $add('Follow-up vencido', 1);
        }

        // ── Renovação aberta / vencida ──
        $renos = CrmOpportunity::where('customer_id', $c->id)->where('tipo', 'renovacao')->where('status', 'aberto')
            ->with('renewalContract:id,data_vencimento')->get();
        if ($renos->isNotEmpty()) {
            $renoVencida = $renos->contains(fn ($r) => $r->renewalContract?->data_vencimento
                && Carbon::parse($r->renewalContract->data_vencimento)->lt($hoje));
            if ($renoVencida) {
                $critico = true;
                $motivos[] = ['texto' => 'Renovação vencida', 'peso' => 0, 'critico' => true];
            } else {
                $add('Renovação aberta', 1);
            }
        }

        // ── Margem (financeiro) ──
        if ($margem !== null && $margem < 0) {
            $critico = true;
            $motivos[] = ['texto' => 'Margem negativa', 'peso' => 3, 'critico' => true];
            $score += 3;
        }

        // ── Classificação ──
        $status = $critico ? 'critico' : ($score >= 4 ? 'critico' : ($score >= 1 ? 'atencao' : 'saudavel'));

        return [
            'score'            => $score,
            'status'           => $status,
            'critico_imediato' => $critico,
            'motivos'          => $motivos,
        ];
    }
}
