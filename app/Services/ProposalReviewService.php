<?php

namespace App\Services;

use App\Models\CrmOpportunityEvent;
use App\Models\CrmProposal;
use App\Models\CrmProposalParticipant;
use App\Models\CrmProposalReviewMessage;
use App\Models\CrmProposalReviewThread;
use App\Models\CrmProposalSection;
use App\Models\User;

/**
 * P-C.2 — Threads de revisão POR SEÇÃO. Comentários sempre no contexto de uma seção (nada global).
 * Mensagens append-only (histórico preservado entre versões). Status: aberta → respondida → resolvida.
 */
class ProposalReviewService
{
    /** Abre uma thread numa seção (1ª mensagem = o texto inicial). */
    public function abrirThread(CrmProposal $p, CrmProposalSection $section, CrmProposalParticipant $by, string $subject, string $message): CrmProposalReviewThread
    {
        $thread = CrmProposalReviewThread::create([
            'crm_proposal_id' => $p->id, 'crm_proposal_section_id' => $section->id, 'section_key' => $section->section_key,
            'created_by_participant_id' => $by->id, 'subject' => $subject, 'status' => 'aberta',
            'opened_revision_number' => (int) ($p->versao ?: 1),
        ]);
        CrmProposalReviewMessage::create([
            'crm_proposal_review_thread_id' => $thread->id, 'crm_proposal_participant_id' => $by->id, 'message' => $message,
        ]);
        $this->marco($p, "{$by->name} abriu uma revisão em \"{$section->title}\": {$subject}", 'thread_aberta', [
            'thread_id' => $thread->id, 'section_key' => $section->section_key,
        ]);
        return $thread;
    }

    /** Acrescenta mensagem a uma thread (cliente OU comercial). aberta → respondida. */
    public function mensagem(CrmProposalReviewThread $t, ?CrmProposalParticipant $part, ?User $user, string $message): CrmProposalReviewMessage
    {
        $msg = CrmProposalReviewMessage::create([
            'crm_proposal_review_thread_id' => $t->id,
            'crm_proposal_participant_id'   => $part?->id,
            'author_user_id'                => $user?->id,
            'message'                       => $message,
        ]);
        if ($t->status === 'aberta') $t->update(['status' => 'respondida']);
        $autor = $part?->name ?: ($user?->name ? $user->name . ' (comercial)' : 'Participante');
        $this->marco($t->proposal, "{$autor} comentou em \"{$t->subject}\"", 'comentario_adicionado', [
            'thread_id' => $t->id, 'section_key' => $t->section_key,
        ]);
        return $msg;
    }

    /** Resolve a thread; se a proposta estava em_revisao e não há mais threads abertas, retorna a em_analise. */
    public function resolver(CrmProposalReviewThread $t, ?CrmProposalParticipant $part, ?User $user): void
    {
        if ($t->isResolvida()) return;
        $t->update([
            'status' => 'resolvida', 'resolved_at' => now(),
            'resolved_revision_number' => (int) ($t->proposal->versao ?: 1),
            'resolved_by_participant_id' => $part?->id, 'resolved_by_user_id' => $user?->id,
        ]);
        $autor = $part?->name ?: ($user?->name ? $user->name . ' (comercial)' : 'Sistema');
        $this->marco($t->proposal, "{$autor} resolveu a revisão \"{$t->subject}\"", 'thread_resolvida', [
            'thread_id' => $t->id, 'section_key' => $t->section_key,
        ]);
        $this->talvezEncerrarRevisao($t->proposal->fresh());
    }

    /** Encerramento: sem threads abertas + proposta em_revisao → volta a em_analise. */
    public function talvezEncerrarRevisao(CrmProposal $p): void
    {
        if ($p->status !== 'em_revisao') return;
        $abertas = CrmProposalReviewThread::where('crm_proposal_id', $p->id)->where('status', '!=', 'resolvida')->count();
        if ($abertas === 0) {
            $p->update(['status' => 'em_analise']);
            $this->marco($p, "Todas as revisões resolvidas — proposta {$p->codigo} retornou para análise", 'revisao_encerrada', []);
        }
    }

    /** [section_key => quantidade total de threads] para os badges do Portal. */
    public function countsBySection(CrmProposal $p): array
    {
        return CrmProposalReviewThread::where('crm_proposal_id', $p->id)
            ->selectRaw('section_key, count(*) as total')->groupBy('section_key')
            ->pluck('total', 'section_key')->map(fn ($v) => (int) $v)->all();
    }

    /** [section_key => threads PENDENTES (aberta+respondida)] — para o destaque visual do menu. */
    public function pendingCountsBySection(CrmProposal $p): array
    {
        return CrmProposalReviewThread::where('crm_proposal_id', $p->id)
            ->whereIn('status', CrmProposalReviewThread::STATUSES_PENDENTES)
            ->selectRaw('section_key, count(*) as total')->groupBy('section_key')
            ->pluck('total', 'section_key')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Resumo executivo de revisões + métricas operacionais simples (sem dashboards/gráficos).
     * "Pendente" = aberta + respondida aguardando validação. Não conta resolvidas.
     */
    public function summary(CrmProposal $p): array
    {
        $titulos = $p->sections()->pluck('title', 'section_key');
        $threads = CrmProposalReviewThread::where('crm_proposal_id', $p->id)->get();

        $pendentes  = $threads->whereIn('status', CrmProposalReviewThread::STATUSES_PENDENTES);
        $resolvidas = $threads->where('status', 'resolvida');

        $porSecao = $pendentes->groupBy('section_key')->map(fn ($g, $key) => [
            'section_key' => $key, 'section_title' => $titulos[$key] ?? $key, 'count' => $g->count(),
        ])->values()->all();

        // Tempo médio de resolução (horas) sobre as resolvidas.
        $tempos = $resolvidas->filter(fn ($t) => $t->resolved_at && $t->created_at)
            ->map(fn ($t) => $t->created_at->diffInMinutes($t->resolved_at));
        $tempoMedioH = $tempos->isNotEmpty() ? round($tempos->avg() / 60, 1) : null;

        // Última atividade de revisão = mensagem mais recente entre as threads.
        $ultima = CrmProposalReviewMessage::whereIn('crm_proposal_review_thread_id', $threads->pluck('id'))
            ->with(['participant:id,name', 'authorUser:id,name'])->orderByDesc('id')->first();
        $ultimoAutor = $ultima?->participant?->name
            ?: ($ultima?->authorUser?->name ? $ultima->authorUser->name . ' (comercial)' : null);

        return [
            'pendentes_total'   => $pendentes->count(),
            'por_secao'         => $porSecao,
            'total_abertas'     => $pendentes->count(),
            'total_resolvidas'  => $resolvidas->count(),
            'tempo_medio_resolucao_horas' => $tempoMedioH,
            'ultima_revisao_at' => optional($ultima?->created_at)->toIso8601String(),
            'ultimo_participante' => $ultimoAutor,
        ];
    }

    /** Threads + mensagens de uma seção (serializado p/ o Portal/gestão). */
    public function forSection(CrmProposal $p, string $sectionKey): array
    {
        $threads = CrmProposalReviewThread::with(['messages.participant:id,name', 'messages.authorUser:id,name', 'author:id,name'])
            ->where('crm_proposal_id', $p->id)->where('section_key', $sectionKey)->orderBy('id')->get();
        return $threads->map(fn ($t) => $this->serializeThread($t))->all();
    }

    public function serializeThread(CrmProposalReviewThread $t): array
    {
        return [
            'id' => $t->id, 'section_key' => $t->section_key, 'subject' => $t->subject, 'status' => $t->status,
            'created_by' => $t->author?->name, 'resolved_at' => optional($t->resolved_at)->toIso8601String(),
            'opened_revision' => $t->opened_revision_number, 'resolved_revision' => $t->resolved_revision_number,
            'messages' => $t->messages->map(fn ($m) => [
                'id' => $m->id,
                'author' => $m->participant?->name ?: ($m->authorUser?->name ? $m->authorUser->name . ' (comercial)' : 'Participante'),
                'is_internal' => $m->author_user_id !== null,
                'message' => $m->message,
                'created_at' => optional($m->created_at)->toIso8601String(),
            ])->all(),
        ];
    }

    private function marco(CrmProposal $p, string $label, string $kind, array $meta): void
    {
        if (!$p->opportunity_id) return;
        CrmOpportunityEvent::log((int) $p->opportunity_id, 'note', [
            'to_value' => $label, 'triggered_by' => null, 'meta' => array_merge(['kind' => $kind], $meta),
        ]);
    }
}
