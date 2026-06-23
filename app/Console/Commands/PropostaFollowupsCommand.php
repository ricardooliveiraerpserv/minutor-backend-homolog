<?php

namespace App\Console\Commands;

use App\Models\CrmOpportunityEvent;
use App\Models\CrmProposalShare;
use App\Models\CrmTask;
use App\Models\DocumentEvent;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Portal de Propostas (Fase 2) — automações de engajamento. Roda diariamente:
 *
 *  A) Expira links vencidos (validade ultrapassada) → status 'expirada' + DocumentEvent + marco.
 *  B) Cenário 1: enviada há ≥3 dias SEM abertura → CrmTask (categoria 'proposta').
 *  C) Cenário 2: aberta porém SEM interação há ≥7 dias → CrmTask (categoria 'retorno').
 *
 * Idempotente: tarefas deduplicadas por (oportunidade + título) em aberto; expiração marcada 1x.
 */
class PropostaFollowupsCommand extends Command
{
    protected $signature = 'crm:proposta-followups {--dry-run : Não grava, só relata}';
    protected $description = 'Follow-ups automáticos das propostas (sem abertura / sem interação) e expiração de links';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $now = Carbon::now();
        $expiradas = $semAbertura = $semInteracao = 0;

        // A) Expiração de links vencidos.
        $aExpirar = CrmProposalShare::with(['proposal', 'document'])
            ->whereNotNull('expires_at')->where('expires_at', '<', $now)
            ->whereNull('revoked_at')->whereNull('accepted_at')->whereNull('rejected_at')
            ->whereNull('expired_marked_at')->get();
        foreach ($aExpirar as $s) {
            $p = $s->proposal;
            if (!$p) continue;
            if (!$dry) {
                $s->update(['expired_marked_at' => $now]);
                if (in_array($p->status, ['enviada', 'em_negociacao', 'reativada'], true)) {
                    $p->update(['status' => 'expirada']);
                }
                $s->document?->update(['status' => 'expirada']);
                $s->document?->logEvent(DocumentEvent::TYPE_EXPIRADO, ['share_id' => $s->id], null);
                $this->marco($p, "Proposta {$p->codigo} expirou sem decisão", 'proposta_expirada', $s->id);
            }
            $expiradas++;
        }

        // B) Enviada há ≥3 dias sem nenhuma abertura.
        $semOpen = CrmProposalShare::with(['proposal'])
            ->whereNotNull('sent_at')->where('sent_at', '<=', $now->copy()->subDays(3))
            ->whereNull('first_viewed_at')->whereNull('revoked_at')
            ->whereNull('accepted_at')->whereNull('rejected_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->get();
        foreach ($semOpen as $s) {
            $p = $s->proposal;
            if (!$p || !$p->opportunity_id || !in_array($p->status, ['enviada', 'em_elaboracao', 'reativada'], true)) continue;
            $titulo = "Follow-up: proposta {$p->codigo} enviada sem abertura";
            if ($this->criaTask($p, 'proposta', $titulo, 'Enviada há 3+ dias e ainda não foi aberta pelo cliente. Reforçar o contato.', $dry)) $semAbertura++;
        }

        // C) Aberta porém sem interação há ≥7 dias.
        $semInter = CrmProposalShare::with(['proposal'])
            ->whereNotNull('first_viewed_at')->whereNotNull('last_viewed_at')
            ->where('last_viewed_at', '<=', $now->copy()->subDays(7))
            ->whereNull('revoked_at')->whereNull('accepted_at')->whereNull('rejected_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->get();
        foreach ($semInter as $s) {
            $p = $s->proposal;
            if (!$p || !$p->opportunity_id || !in_array($p->status, ['enviada', 'em_negociacao', 'reativada'], true)) continue;
            $titulo = "Retorno comercial: proposta {$p->codigo} sem interação há 7 dias";
            if ($this->criaTask($p, 'retorno', $titulo, 'Cliente abriu a proposta mas não interage há 7+ dias. Fazer um retorno comercial.', $dry)) $semInteracao++;
        }

        $this->info(($dry ? '[dry-run] ' : '') . "Expiradas: {$expiradas} · Follow-ups sem abertura: {$semAbertura} · Retornos sem interação: {$semInteracao}");
        return self::SUCCESS;
    }

    private function marco($proposal, string $label, string $kind, int $shareId): void
    {
        if (!$proposal?->opportunity_id) return;
        CrmOpportunityEvent::log((int) $proposal->opportunity_id, 'note', [
            'to_value' => $label, 'triggered_by' => null, 'meta' => ['kind' => $kind, 'share_id' => $shareId],
        ]);
    }

    /** Cria a tarefa se ainda não existir uma igual em aberto. Retorna true se criou. */
    private function criaTask($p, string $categoria, string $titulo, string $notas, bool $dry): bool
    {
        $existe = CrmTask::where('opportunity_id', $p->opportunity_id)
            ->where('titulo', $titulo)->whereNull('concluida_at')->exists();
        if ($existe) return false;
        if ($dry) return true;
        $resp = $p->vendedor_id ?? optional($p->opportunity)->responsavel_id;
        CrmTask::create([
            'customer_id'    => $p->customer_id,
            'opportunity_id' => $p->opportunity_id,
            'tipo'           => 'email',
            'categoria'      => $categoria,
            'titulo'         => $titulo,
            'data'           => Carbon::now(),
            'responsavel_id' => $resp,
            'prioridade'     => 'alta',
            'notas'          => $notas,
            'created_by_id'  => $resp,
        ]);
        return true;
    }
}
