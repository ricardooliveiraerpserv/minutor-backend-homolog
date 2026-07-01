<?php

namespace App\Services;

use App\Models\CrmOpportunity;
use App\Models\CrmOpportunityEvent;
use Illuminate\Support\Carbon;

/**
 * SAÚDE DA OPORTUNIDADE — mede CHANCE REAL DE FECHAMENTO, não só atividade.
 * Combina HIGIENE (interação, próxima ação, tempo na etapa, previsão) com
 * QUALIDADE DO DEAL (proposta enviada, decisor, champion, budget) — sinais MEDDIC/BANT.
 * $ctx['proposta_enviada'] evita query por card no kanban.
 * @return array{status:string,score:int,motivos:array,diagnostico:string,qualidade:string,sinais:array}
 */
class OpportunityHealthService
{
    public function compute(CrmOpportunity $o, ?int $diasNaEtapa = null, array $ctx = []): array
    {
        $aberto = $o->status === 'aberto';
        $motivos = [];
        $score = 0;
        $critico = false;

        // Sinais de QUALIDADE do deal (MEDDIC/BANT). Flags manuais ficam em `detalhes`.
        $det = (array) ($o->detalhes ?? []);
        $propostaEnviada = $ctx['proposta_enviada'] ?? $this->temPropostaEnviada($o);
        $decisor  = !empty($det['decisor_identificado']);
        $champion = !empty($det['champion_identificado']);
        $budget   = !empty($det['budget_confirmado']);
        $sinais = ['proposta_enviada' => $propostaEnviada, 'decisor' => $decisor, 'champion' => $champion, 'budget' => $budget];
        $qScore = ($propostaEnviada ? 1 : 0) + ($decisor ? 1 : 0) + ($champion ? 1 : 0) + ($budget ? 1 : 0);
        $qualidade = $qScore >= 3 ? 'alta' : ($qScore >= 1 ? 'media' : 'baixa');

        $diasSem = $o->ultima_interacao_at ? (int) $o->ultima_interacao_at->diffInDays(now()) : null;
        if ($aberto) {
            // HIGIENE
            if ($diasSem === null) { $motivos[] = 'nunca interagido'; $score += 2; }
            elseif ($diasSem >= 15) { $motivos[] = "sem interação há {$diasSem} dias"; $score += 3; $critico = true; }
            elseif ($diasSem >= 7) { $motivos[] = "sem interação há {$diasSem} dias"; $score += 2; }

            if ($o->proxima_acao_at === null) { $motivos[] = 'sem próxima ação'; $score += 2; }
            elseif ($o->proxima_acao_at->endOfDay()->isPast()) { $motivos[] = 'próxima ação atrasada'; $score += 2; }

            $sla = (int) ($o->stage?->sla_dias ?? 0);
            $dEtapa = $diasNaEtapa ?? $this->diasNaEtapa($o);
            if ($sla > 0 && $dEtapa > $sla) { $motivos[] = "há {$dEtapa} dias na etapa (SLA {$sla})"; $score += 2; }

            // QUALIDADE DO DEAL — peso maior em etapas avançadas.
            $prob = $o->probabilidade ?? (int) ($o->stage?->probabilidade ?? 0);
            $avancado = $prob >= 50;
            if (!$propostaEnviada) { $motivos[] = 'sem proposta enviada'; $score += $avancado ? 3 : 1; if ($avancado) $critico = true; }
            if (!$decisor) { $motivos[] = 'decisor não identificado'; $score += $avancado ? 2 : 1; }
            if (!$budget && $avancado) { $motivos[] = 'budget não confirmado'; $score += 1; }
        }

        $status = $critico || $score >= 4 ? 'em_risco' : ($score >= 1 ? 'atencao' : 'saudavel');
        $diag = !$aberto
            ? ($o->status === 'ganho' ? 'Oportunidade ganha.' : 'Oportunidade perdida.')
            : ($status === 'saudavel' ? 'Chance real alta. Deal qualificado (proposta/decisor/budget) e interação em dia.'
                : ($status === 'em_risco' ? 'Oportunidade em risco. ' . ucfirst(implode(', ', $motivos)) . '.'
                    : 'Atenção. ' . ucfirst(implode(', ', $motivos)) . '.'));

        return ['status' => $status, 'score' => $score, 'motivos' => $motivos, 'diagnostico' => $diag, 'qualidade' => $qualidade, 'sinais' => $sinais];
    }

    /** Tem proposta efetivamente enviada (status além de em_elaboracao)? */
    private function temPropostaEnviada(CrmOpportunity $o): bool
    {
        return \App\Models\CrmProposal::where('opportunity_id', $o->id)->whereNull('deleted_at')
            ->whereNotIn('status', ['em_elaboracao', 'cancelada', 'reprovada', 'expirada'])->exists();
    }

    /** Dias na etapa atual (último stage_changed p/ a etapa, senão criação). */
    public function diasNaEtapa(CrmOpportunity $o): int
    {
        $entrou = CrmOpportunityEvent::where('opportunity_id', $o->id)
            ->where('event_type', 'stage_changed')->where('to_value', $o->stage?->name)
            ->max('created_at') ?? $o->created_at;
        return $entrou ? (int) Carbon::parse($entrou)->diffInDays(now()) : 0;
    }
}
