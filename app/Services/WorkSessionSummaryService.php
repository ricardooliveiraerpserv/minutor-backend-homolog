<?php

namespace App\Services;

use App\Models\HelpDeskTicket;
use App\Models\Timesheet;
use App\Models\WorkSession;
use Illuminate\Support\Collection;

/**
 * Resumo (indicadores) de uma Sessão de Trabalho — a partir dos eventos da sessão +
 * apontamentos da janela. Extraído do WorkSessionController para ser reutilizado pela
 * Central de Operações (que resume VÁRIAS sessões ativas de uma vez) SEM duplicar a regra.
 *
 * Aceita os eventos pré-carregados (anti-N+1) quando o chamador já tem a coleção.
 */
class WorkSessionSummaryService
{
    public function summarize(WorkSession $session, ?Collection $events = null): array
    {
        $ev = $events ?? $session->events()->get();
        $fim = $session->ended_at ?? now();

        $finalizedIds = $ev->where('type', 'ticket_finalized')->pluck('entity_id')->filter()->unique();
        $resolvidos = $finalizedIds->isEmpty() ? 0 : HelpDeskTicket::whereIn('id', $finalizedIds)
            ->whereHas('status', fn ($s) => $s->where('is_resolved', true))->count();

        // Horas apontadas na JANELA da sessão (apontamentos do Help Desk criados pelo operador).
        $horas = (float) Timesheet::where('user_id', $session->user_id)
            ->whereNotNull('helpdesk_ticket_id')->whereNull('deleted_at')
            ->whereBetween('created_at', [$session->started_at, $fim])
            ->sum('effort_minutes') / 60;

        $atendidos = $finalizedIds->count();
        return [
            'atendidos'             => $atendidos,
            'pulados'               => $ev->where('type', 'ticket_skipped')->count(),
            'resolvidos'            => $resolvidos,
            'encaminhados'          => max(0, $atendidos - $resolvidos),
            'playbooks'             => $ev->where('type', 'playbook_executed')->count(),
            'horas_apontadas'       => round($horas, 2),
            'tempo_total_segundos'  => (int) $session->started_at->diffInSeconds($fim),
            'ended'                 => $session->ended_at !== null,
        ];
    }
}
