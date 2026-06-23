<?php

namespace App\Http\Controllers;

use App\Models\CrmOpportunityEvent;
use App\Models\CrmProposal;
use App\Models\CrmProposalShare;
use App\Models\CrmTask;
use App\Models\DocumentEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal de Propostas — acesso PÚBLICO (sem login) por token.
 *
 * Resolve o CrmProposalShare pelo token, serve a proposta (PDF do Document existente)
 * e registra o engajamento como DocumentEvent (fonte oficial) + marcos na timeline da
 * oportunidade (CrmOpportunityEvent). Não duplica versionamento/PDF/status/auditoria.
 *
 * Todas as rotas ficam FORA do auth:sanctum, com throttle (mitiga brute-force de token).
 */
class ProposalPortalController extends Controller
{
    /** Janela (min) para considerar uma nova visita uma "sessão" distinta (evita inflar revisitas em refresh). */
    private const SESSAO_MINUTOS = 30;

    private function resolveShare(string $token): CrmProposalShare
    {
        $share = CrmProposalShare::with(['proposal.customer:id,name,cgc', 'proposal.vendedor:id,name', 'document'])
            ->where('token', $token)->first();
        abort_if(!$share, 404, 'Proposta não encontrada.');
        return $share;
    }

    /** Participante corrente do portal (link ?pt=) — atribui as ações a quem está agindo. */
    private function resolveParticipant(CrmProposalShare $share, Request $request): ?\App\Models\CrmProposalParticipant
    {
        $pt = (string) ($request->input('pt') ?: $request->query('pt', ''));
        if ($pt === '') return null;
        return \App\Models\CrmProposalParticipant::where('participant_token', $pt)
            ->where('crm_proposal_id', $share->proposal_id)->where('is_active', true)->first();
    }

    /** Payload público da proposta + participantes + capacidades do participante corrente. */
    private function payload(CrmProposalShare $share, ?\App\Models\CrmProposalParticipant $me = null): array
    {
        $p   = $share->proposal;
        $svc = app(\App\Documents\CrmProposalService::class);
        $tipoLabels = [
            'bh_fixo' => 'Banco de Horas Fixo', 'bh_mensal' => 'Banco de Horas Mensal',
            'on_demand' => 'Consultoria Sob Demanda', 'projeto_fechado' => 'Projeto Fechado', 'cloud' => 'Cloud Protheus',
        ];
        $st = $p->status;
        $emAnalise = in_array($st, ['enviada', 'em_analise', 'em_negociacao', 'em_revisao', 'em_elaboracao', 'reativada'], true);
        $aprovada  = in_array($st, ['aprovada', 'aguardando_assinatura'], true);
        $decidido  = ($share->rejected_at || $st === 'reprovada') ? 'recusada'
            : (in_array($st, ['assinada', 'liberada', 'convertida'], true) ? 'assinada' : null);
        $ativo = $share->isAtivo();

        $participantes = $p->participants->where('is_active', true)->map(fn ($x) => [
            'id' => $x->id, 'name' => $x->name, 'email' => $x->email, 'roles' => $x->roles,
            'cargo' => $x->cargo, 'parte' => $x->parte,
            'status' => $x->statusLabel(), 'approved' => $x->approved_at !== null, 'signed' => $x->signed_at !== null,
            'approved_at' => optional($x->approved_at)->toIso8601String(), 'signed_at' => optional($x->signed_at)->toIso8601String(),
            'approval_comment' => $x->approval_comment, 'sign_status' => $x->sign_status, 'sign_refusal_reason' => $x->sign_refusal_reason,
        ])->values()->all();

        // P-E.2.4 — envelope Clicksign ativo + link individual por participante.
        $env = \App\Models\ClicksignEnvelope::with('signers')->where('crm_proposal_id', $p->id)->where('is_active', true)->orderByDesc('id')->first();
        $signUrlByPart = $env ? $env->signers->whereNotNull('crm_proposal_participant_id')->keyBy('crm_proposal_participant_id') : collect();

        // Capacidades do participante CORRENTE (P-B): por papel, não por status genérico.
        $podeRevisar = $ativo && $emAnalise && (bool) $me?->hasRole('reviewer');
        $podeAprovar = $ativo && $emAnalise && (bool) $me?->hasRole('approver') && $me?->approved_at === null;
        $podeAssinar = $ativo && $aprovada && (bool) $me?->hasRole('signer') && $me?->signed_at === null;
        // P-E.2.4 — pode INICIAR a assinatura (Portal → Clicksign): signer não-assinado, fora de estados terminais,
        // e sem OUTRO aprovador pendente (a aprovação do próprio assinante é registrada ao assinar).
        $outrosAprovPend = $p->participants->where('is_active', true)
            ->filter(fn ($x) => $x->hasRole('approver') && !$x->approved_at && (!$me || $x->id !== $me->id));
        $podeIniciarAssin = $ativo && (bool) $me?->hasRole('signer') && $me?->signed_at === null
            && !in_array($st, ['reprovada', 'cancelada', 'expirada', 'convertida', 'liberada'], true)
            && $outrosAprovPend->isEmpty();

        // Comentários/revisões SOMENTE em Escopo, Investimento e Prazo de Pagamento (regra de negócio).
        // A proposta em si é exibida pelo deck real (PDF); estas seções servem só p/ ancorar a colaboração.
        $rev = app(\App\Services\ProposalReviewService::class);
        $counts  = $rev->countsBySection($p);
        $pending = $rev->pendingCountsBySection($p);
        $commentable = \App\Services\ProposalSectionService::COMMENTABLE;
        // Página do deck (PDF, 1-based) de cada seção comentável — p/ o Portal posicionar o documento.
        $tipo = $p->tipo ?: 'bh_fixo';
        $isPF = $tipo === 'projeto_fechado'; $isCloud = $tipo === 'cloud';
        $pageOf = [
            'escopo'       => ($isCloud ? 2 : 3) + 1,
            'investimento' => ($isCloud ? 9 : ($isPF ? 5 : 6)) + 1,
            'prazo'        => ($isPF ? 6 : 7) + 1,
        ];
        $sections = array_values(array_filter(array_map(function ($s) use ($counts, $pending, $commentable, $pageOf) {
            if (!in_array($s['key'], $commentable, true)) return null;
            return [
                'key' => $s['key'], 'title' => $s['title'], 'page' => $pageOf[$s['key']] ?? null,
                'threads_count' => (int) ($counts[$s['key']] ?? 0),
                'pending_count' => (int) ($pending[$s['key']] ?? 0),
            ];
        }, app(\App\Services\ProposalSectionService::class)->forPortal($p))));

        return [
            'codigo'      => $p->codigo,
            'tipo_label'  => $tipoLabels[$p->tipo ?? ''] ?? null,
            'sections'    => $sections,
            'cliente'     => $p->customer?->name,
            'valor'       => 'R$ ' . number_format((float) $p->total, 2, ',', '.'),
            'validade'    => optional($p->data_validade)->format('d/m/Y'),
            'contratada'  => $svc->contratadaConfig(),
            'pdf_url'     => "/api/v1/p/{$share->token}/pdf",
            'expirado'    => $share->isExpirado(),
            'revogado'    => $share->isRevogado(),
            'status'      => $st,
            'aprovada'    => $aprovada,
            'decisao'     => $decidido,
            'participantes' => $participantes,
            'participante'  => $me ? ['id' => $me->id, 'name' => $me->name, 'roles' => $me->roles, 'approved' => $me->approved_at !== null, 'signed' => $me->signed_at !== null] : null,
            'pode_revisar'  => $podeRevisar,
            'pode_aprovar'  => $podeAprovar,
            'pode_assinar'  => $podeAssinar,
            'pode_iniciar_assinatura' => $podeIniciarAssin,
            'aprov_pendente_outros' => $outrosAprovPend->pluck('name')->values()->all(),
            // P-E.2.4 — Aprovação e Assinatura integradas no Portal.
            'aprovacao'     => $this->blocoAprovacao($p),
            'assinatura'    => $this->blocoAssinatura($p, $env),
            'timeline'      => $this->timelineFormalizacao($p, $env),
            // Link individual de assinatura (Clicksign) do participante corrente (redireciona; não assina no portal).
            'sign_url'      => $me && isset($signUrlByPart[$me->id]) ? $signUrlByPart[$me->id]->sign_url : null,
            'envelope_ativo' => $env !== null,
            // P-C.2 — capacidades de colaboração por papel (viewer = só leitura).
            'pode_abrir_thread' => (bool) ($me?->hasRole('reviewer') || $me?->hasRole('approver')),
            'pode_comentar'     => (bool) ($me?->hasRole('reviewer') || $me?->hasRole('approver') || $me?->hasRole('signer')),
            // Governança P-C — resumo executivo de revisões + métricas simples + versão atual.
            'versao'         => (int) ($p->versao ?: 1),
            'review_summary' => $rev->summary($p),
        ];
    }

    /** GET /p/{token}[?pt=] — dados públicos + participantes + registra a visualização/acesso. */
    public function show(Request $request, string $token): JsonResponse
    {
        $share = $this->resolveShare($token);
        $me = $this->resolveParticipant($share, $request);
        if ($me) app(\App\Services\ProposalParticipantService::class)->registrarAcesso($me, $request->ip(), $request->userAgent());
        if ($share->isAtivo() && !$share->rejected_at) {
            $this->registrarVisita($request, $share);
        }
        $fresh = $share->fresh(['proposal.customer', 'proposal.vendedor', 'proposal.participants', 'document']);
        return response()->json(['data' => $this->payload($fresh, $me?->fresh())]);
    }

    private function registrarVisita(Request $request, CrmProposalShare $share): void
    {
        $now     = now();
        $isFirst = $share->first_viewed_at === null;
        $novaSessao = $isFirst || $share->last_viewed_at === null || $share->last_viewed_at->diffInMinutes($now) >= self::SESSAO_MINUTOS;

        if ($isFirst) $share->first_viewed_at = $now;
        $share->last_viewed_at = $now;
        if ($novaSessao) $share->view_count = (int) $share->view_count + 1;
        $share->save();

        if ($novaSessao && $share->document) {
            $meta = ['share_id' => $share->id, 'ip' => $request->ip(), 'ua' => substr((string) $request->userAgent(), 0, 240)];
            $share->document->logEvent($isFirst ? DocumentEvent::TYPE_VISUALIZADO : DocumentEvent::TYPE_REVISITADO, $meta, null);
            if ($isFirst && $share->proposal?->opportunity_id) {
                CrmOpportunityEvent::log((int) $share->proposal->opportunity_id, 'note', [
                    'to_value'     => "Proposta {$share->proposal->codigo} aberta pela 1ª vez",
                    'triggered_by' => null,
                    'meta'         => ['kind' => 'proposta_aberta', 'share_id' => $share->id],
                ]);
            }
        }
        // P-A: a 1ª abertura move enviada → em_analise (a assinatura nunca inicia na 1ª abertura).
        if ($isFirst && $share->proposal && $share->proposal->status === 'enviada') {
            $share->proposal->update(['status' => 'em_analise']);
        }
    }

    /** GET /p/{token}/pdf — serve o PDF do Document (inline). */
    public function pdf(string $token)
    {
        $share = $this->resolveShare($token);
        abort_if($share->isRevogado(), 410, 'Este link foi revogado.');
        // P-E.2.4 — se houver PDF ASSINADO (Clicksign capturado), serve o assinado como corpo oficial da proposta.
        $doc = $share->document;
        $att = ($doc && $doc->signed_attachment_id ? $doc->signedAttachment : null) ?: $doc?->attachment;
        abort_if(!$att, 404, 'PDF indisponível.');
        try {
            $bytes = app(\App\Attachments\Storage\StorageProvider::class)->get($att->storage_path);
        } catch (\Throwable $e) {
            abort(404, 'PDF indisponível.');
        }
        $nome = 'Proposta-' . str_replace(['/', ' '], '-', (string) $share->proposal?->codigo) . '.pdf';
        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $nome . '"',
            'Cache-Control'       => 'private, max-age=300',
        ]);
    }

    /** POST /p/{token}/heartbeat — acumula tempo de leitura (permanência). */
    public function heartbeat(Request $request, string $token): JsonResponse
    {
        $share = $this->resolveShare($token);
        $secs  = max(0, min(120, (int) $request->input('seconds', 0))); // teto por ping evita abuso
        if ($share->isAtivo() && $secs > 0) {
            $share->read_seconds = (int) $share->read_seconds + $secs;
            $share->last_viewed_at = now();
            $share->save();
        }
        return response()->json(['ok' => true]);
    }

    /**
     * POST /p/{token}/secao — tracking de seção (P-C.1).
     * action=entered → abre a visita (1ª vez na sessão registra "Seção X visualizada" na timeline).
     * action=exited  → fecha a visita aberta mais recente com a duração (duration_seconds).
     */
    public function registrarSecao(Request $request, string $token): JsonResponse
    {
        $share = $this->resolveShare($token);
        if (!$share->isAtivo()) return response()->json(['ok' => false]);

        $v = $request->validate([
            'section_key'      => 'required|string|max:40',
            'action'           => 'required|in:entered,exited',
            'duration_seconds' => 'nullable|integer|min:0|max:7200',
        ]);
        $key = $v['section_key'];
        $me  = $this->resolveParticipant($share, $request);
        $p   = $share->proposal;

        // Só rastreia seções reais e visíveis da proposta.
        $valida = $p->sections()->where('section_key', $key)->where('visible', true)->exists();
        if (!$valida) return response()->json(['ok' => false]);
        $titulo = $p->sections()->where('section_key', $key)->value('title') ?: $key;

        if ($v['action'] === 'entered') {
            $primeira = !\App\Models\CrmProposalSectionView::where('crm_proposal_share_id', $share->id)
                ->where('section_key', $key)->exists();
            \App\Models\CrmProposalSectionView::create([
                'crm_proposal_id' => $p->id, 'crm_proposal_share_id' => $share->id,
                'crm_proposal_participant_id' => $me?->id, 'section_key' => $key, 'entered_at' => now(),
            ]);
            if ($primeira && $p->opportunity_id) {
                CrmOpportunityEvent::log((int) $p->opportunity_id, 'note', [
                    'to_value' => "Seção \"{$titulo}\" visualizada" . ($me ? " por {$me->name}" : ''),
                    'triggered_by' => null,
                    'meta' => ['kind' => 'secao_visualizada', 'section_key' => $key, 'share_id' => $share->id, 'participant_id' => $me?->id],
                ]);
            }
        } else { // exited
            $aberta = \App\Models\CrmProposalSectionView::where('crm_proposal_share_id', $share->id)
                ->where('section_key', $key)->whereNull('exited_at')->orderByDesc('id')->first();
            $dur = (int) ($v['duration_seconds'] ?? 0);
            if ($aberta) {
                $aberta->update(['exited_at' => now(), 'duration_seconds' => $dur]);
            } else {
                \App\Models\CrmProposalSectionView::create([
                    'crm_proposal_id' => $p->id, 'crm_proposal_share_id' => $share->id,
                    'crm_proposal_participant_id' => $me?->id, 'section_key' => $key,
                    'entered_at' => now()->subSeconds($dur), 'exited_at' => now(), 'duration_seconds' => $dur,
                ]);
            }
        }
        return response()->json(['ok' => true]);
    }

    private function exigeParticipante(CrmProposalShare $share, Request $request): \App\Models\CrmProposalParticipant
    {
        abort_if(!$share->isAtivo(), 410, 'Este link não está mais disponível.');
        $me = $this->resolveParticipant($share, $request);
        abort_if(!$me, 422, 'Identifique-se como participante (link de convite) para esta ação.');
        return $me;
    }

    /**
     * P-E.2.4 — POST /p/{token}/identificar: identifica o visitante por E-MAIL.
     * Só e-mails JÁ CADASTRADOS como participantes recebem seus papéis (assinar/revisar/aprovar);
     * e-mail desconhecido = apenas visualização (não cria participante nem concede papéis).
     */
    public function identificar(Request $request, string $token): JsonResponse
    {
        $share = $this->resolveShare($token);
        abort_if(!$share->isAtivo(), 410, 'Este link não está mais disponível.');
        $v = $request->validate(['email' => 'required|email']);
        $email = mb_strtolower(trim($v['email']));
        $part = $share->proposal->participants()->where('is_active', true)
            ->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($part) {
            return response()->json(['data' => ['matched' => true, 'pt' => $part->participant_token, 'name' => $part->name, 'roles' => $part->roles]]);
        }
        return response()->json(['data' => ['matched' => false]]); // visitante = somente visualização
    }

    /** POST /p/{token}/participantes — cliente adiciona um participante (multi-papéis) + convite. */
    public function adicionarParticipante(Request $request, string $token): JsonResponse
    {
        $share = $this->resolveShare($token);
        abort_if(!$share->isAtivo(), 410, 'Este link não está mais disponível.');
        $v = $request->validate([
            'name'    => 'required|string|max:160',
            'email'   => 'required|email',
            'roles'   => 'required|array|min:1',
            'roles.*' => 'in:viewer,reviewer,approver,signer',
        ]);
        $part = app(\App\Services\ProposalParticipantService::class)->adicionar($share->proposal, $v['name'], $v['email'], $v['roles'], null);
        // Devolve o token p/ o próprio participante já interagir (vira o ?pt da sessão dele).
        return response()->json(['data' => ['id' => $part->id, 'name' => $part->name, 'pt' => $part->participant_token]], 201);
    }

    /** POST /p/{token}/revisao — Reviewer solicita revisão (→ em_revisao). */
    public function solicitarRevisao(Request $request, string $token): JsonResponse
    {
        $request->validate(['motivo' => 'nullable|string|max:1000', 'secao' => 'nullable|string|max:40']);
        $share = $this->resolveShare($token);
        $me = $this->exigeParticipante($share, $request);
        if (!$me->hasRole('reviewer')) return response()->json(['message' => 'Você não tem papel de Revisor.'], 403);
        $p = $share->proposal;
        if (!in_array($p->status, ['enviada', 'em_analise', 'em_negociacao', 'em_revisao'], true)) {
            return response()->json(['message' => 'A proposta não está em fase de análise.'], 409);
        }
        $motivo = trim((string) $request->input('motivo'));
        $p->update(['status' => 'em_revisao']);
        $this->marco($p, "{$me->name} SOLICITOU REVISÃO" . ($motivo ? " — {$motivo}" : ''), 'revisao_solicitada', $share->id);
        $this->followUp($p, 'retorno', "Proposta {$p->codigo} — revisão solicitada", $motivo ?: "Revisão solicitada por {$me->name} pelo Portal.");
        return response()->json(['data' => ['status' => 'em_revisao']]);
    }

    /** P-E.2.4 — Bloco de Aprovação (validadores + status/data/recusa). */
    private function blocoAprovacao(CrmProposal $p): array
    {
        $aprovadores = $p->participants->where('is_active', true)->filter(fn ($x) => $x->hasRole('approver'));
        $itens = $aprovadores->map(function ($x) {
            $st = $x->approved_at ? 'aprovado' : ($x->sign_status === 'refused' ? 'recusado' : 'pendente');
            return [
                'id' => $x->id, 'nome' => $x->name, 'cargo' => $x->cargo, 'status' => $st,
                'data' => optional($x->approved_at)->toIso8601String(),
                'comentario' => $x->approval_comment, 'motivo_recusa' => $x->sign_refusal_reason,
            ];
        })->values()->all();
        $pendentes = $aprovadores->filter(fn ($x) => !$x->approved_at)->pluck('name')->values()->all();
        return [
            'tem_aprovacao' => $aprovadores->isNotEmpty(),
            'concluida' => $aprovadores->isNotEmpty() && $aprovadores->every(fn ($x) => $x->approved_at),
            'itens' => $itens,
            'pendentes' => $pendentes,
        ];
    }

    /** P-E.2.4 — Bloco de Assinatura por parte (Contratada/Contratante) + bloqueadores + status geral. */
    private function blocoAssinatura(CrmProposal $p, ?\App\Models\ClicksignEnvelope $env): array
    {
        $signers = $p->participants->where('is_active', true)->filter(fn ($x) => $x->hasRole('signer'));
        $modo = $p->assinatura_modo ?? 'todos';
        $rotulo = ['contratada' => 'Contratada', 'contratante' => 'Contratante', 'indefinida' => 'Parte não definida'];
        $grupos = [];
        foreach (['contratada', 'contratante', 'indefinida'] as $key) {
            $g = $signers->filter(fn ($x) => ($x->parte ?: 'indefinida') === $key);
            if ($g->isEmpty()) continue;
            $assinados = $g->filter(fn ($x) => $x->signed_at !== null);
            $grupos[] = [
                'parte' => $key, 'label' => $rotulo[$key],
                'assinantes' => $g->map(fn ($x) => [
                    'id' => $x->id, 'nome' => $x->name, 'cargo' => $x->cargo,
                    'assinado' => $x->signed_at !== null, 'data' => optional($x->signed_at)->toIso8601String(),
                    'status' => $x->sign_status, 'motivo' => $x->sign_refusal_reason,
                ])->values()->all(),
                'ok' => $modo === 'um_por_parte' ? $assinados->isNotEmpty() : $assinados->count() === $g->count(),
            ];
        }
        // Concluída pelo modo.
        $completa = $signers->isNotEmpty();
        if ($modo === 'um_por_parte') {
            foreach ($signers->groupBy(fn ($x) => $x->parte ?: 'indefinida') as $grp) { if (!$grp->contains(fn ($x) => $x->signed_at)) $completa = false; }
        } else {
            $completa = $signers->isNotEmpty() && !$signers->contains(fn ($x) => $x->signed_at === null);
        }
        // Bloqueadores: pendentes que de fato impedem a conclusão (no um_por_parte, só partes sem nenhuma assinatura).
        $pendentes = $signers->filter(fn ($x) => !$x->signed_at);
        $bloqueadores = ($modo === 'um_por_parte')
            ? $pendentes->filter(fn ($x) => !$signers->filter(fn ($y) => ($y->parte ?: 'i') === ($x->parte ?: 'i'))->contains(fn ($y) => $y->signed_at))
            : $pendentes;

        $statusGeral = $signers->isEmpty() ? 'sem_assinantes'
            : (in_array($p->status, ['assinada', 'liberada', 'convertida'], true) || $completa ? 'concluida'
                : ($env ? 'aguardando' : 'nao_enviada'));

        return [
            'modo' => $modo,
            'modo_label' => $modo === 'um_por_parte' ? 'Pelo menos um assinante por parte' : 'Todos devem assinar',
            'status_geral' => $statusGeral,
            'completa' => $completa,
            'grupos' => $grupos,
            'bloqueadores' => $bloqueadores->map(fn ($x) => ['nome' => $x->name, 'parte' => $rotulo[$x->parte ?: 'indefinida'] ?? null])->values()->all(),
            'envelope_ativo' => $env !== null,
        ];
    }

    /** P-E.2.4 — Histórico da Formalização (aprovações, envio, assinaturas, conclusão). */
    private function timelineFormalizacao(CrmProposal $p, ?\App\Models\ClicksignEnvelope $env): array
    {
        $ev = [];
        foreach ($p->participants->where('is_active', true) as $x) {
            if ($x->approved_at) $ev[] = ['em' => $x->approved_at, 'icon' => '✔', 'texto' => "{$x->name} aprovou a proposta"];
            if ($x->signed_at) $ev[] = ['em' => $x->signed_at, 'icon' => '✍', 'texto' => "{$x->name} assinou"];
            if ($x->sign_status === 'refused' && $x->sign_status_at) $ev[] = ['em' => $x->sign_status_at, 'icon' => '❌', 'texto' => "{$x->name} recusou" . ($x->sign_refusal_reason ? " — {$x->sign_refusal_reason}" : '')];
        }
        if ($env && $env->sent_at) $ev[] = ['em' => $env->sent_at, 'icon' => '✉', 'texto' => 'Solicitação de assinatura enviada'];
        if (in_array($p->status, ['assinada', 'liberada', 'convertida'], true)) {
            $ult = $p->participants->where('is_active', true)->filter(fn ($x) => $x->hasRole('signer') && $x->signed_at)->max('signed_at');
            if ($ult) $ev[] = ['em' => $ult, 'icon' => '✔', 'texto' => 'Proposta formalizada'];
        }
        usort($ev, fn ($a, $b) => $a['em'] <=> $b['em']);
        return array_map(fn ($e) => ['em' => \Illuminate\Support\Carbon::parse($e['em'])->toIso8601String(), 'icon' => $e['icon'], 'texto' => $e['texto']], $ev);
    }

    /** POST /p/{token}/aprovar — Approver aprova (proposta=aprovada só quando TODOS aprovarem). */
    public function aprovar(Request $request, string $token): JsonResponse
    {
        $request->validate(['comentario' => 'nullable|string|max:1000']);
        $share = $this->resolveShare($token);
        $me = $this->exigeParticipante($share, $request);
        try {
            app(\App\Services\ProposalParticipantService::class)->aprovar($me, $request->ip(), $request->userAgent(), trim((string) $request->input('comentario')) ?: null);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['data' => ['status' => $share->proposal->fresh()->status, 'approved' => true]]);
    }

    /** POST /p/{token}/assinar — Signer assina digitalmente (nome/CPF/cargo + traço + evidências). */
    public function assinar(Request $request, string $token): JsonResponse
    {
        $v = $request->validate([
            'nome'   => 'required|string|max:160',
            'cpf'    => 'nullable|string|max:20',
            'cargo'  => 'nullable|string|max:120',
            'imagem' => 'nullable|string', // data-URL (image/png base64) do traço
        ]);
        $share = $this->resolveShare($token);
        $me = $this->exigeParticipante($share, $request);
        if ($erro = $this->liberarParaAssinatura($share->proposal, $me, $request)) {
            return response()->json(['message' => $erro], 422);
        }
        try {
            app(\App\Services\ProposalParticipantService::class)->assinar($me, $request->ip(), $request->userAgent(), [
                'nome' => $v['nome'], 'cpf' => $v['cpf'] ?? null, 'cargo' => $v['cargo'] ?? null,
                'imagem' => (isset($v['imagem']) && str_starts_with((string) $v['imagem'], 'data:image/')) ? mb_substr($v['imagem'], 0, 600000) : null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['data' => ['status' => $share->proposal->fresh()->status, 'signed' => true]]);
    }

    /**
     * P-E.2.4 — Libera o participante para assinar (nativo ou Clicksign): bloqueia só se houver OUTRO
     * aprovador pendente; auto-aprova o próprio assinante (assinar pressupõe aprovar) e garante status apto.
     * Retorna mensagem de erro (string) se bloqueado, ou null se liberado.
     */
    private function liberarParaAssinatura(CrmProposal $p, \App\Models\CrmProposalParticipant $me, Request $request): ?string
    {
        if (!$me->hasRole('signer')) return 'Você não está definido como assinante desta proposta.';
        if (in_array($p->status, ['reprovada', 'cancelada', 'expirada', 'convertida', 'liberada'], true)) {
            return 'Esta proposta não está disponível para assinatura.';
        }
        $outros = $p->participants()->where('is_active', true)->get()
            ->filter(fn ($x) => $x->hasRole('approver') && !$x->approved_at && $x->id !== $me->id);
        if ($outros->isNotEmpty()) return 'Aguardando aprovação de: ' . $outros->pluck('name')->implode(', ') . '.';
        if ($me->hasRole('approver') && !$me->approved_at) {
            try { app(\App\Services\ProposalParticipantService::class)->aprovar($me, $request->ip(), $request->userAgent(), 'Aprovado ao assinar'); $me->refresh(); } catch (\Throwable $e) { /* segue */ }
        }
        // Sem aprovação formal pendente → garante status apto p/ a assinatura nativa (não há envelope).
        if (!in_array($p->fresh()->status, ['aprovada', 'aguardando_assinatura'], true)) {
            $p->update(['status' => 'aprovada']);
        }
        return null;
    }

    /**
     * P-E.2.4 — POST /p/{token}/iniciar-assinatura: garante o envelope Clicksign (cria se ainda não existir,
     * remetente = vendedor/criador da proposta) e devolve o link individual de assinatura do participante (redireciona).
     */
    public function iniciarAssinatura(Request $request, string $token): JsonResponse
    {
        $share = $this->resolveShare($token);
        $me = $this->exigeParticipante($share, $request);
        $p = $share->proposal;
        if (!$me->hasRole('signer')) return response()->json(['message' => 'Você não está definido como assinante desta proposta.'], 403);
        if (in_array($p->status, ['reprovada', 'cancelada', 'expirada', 'convertida', 'liberada'], true)) {
            return response()->json(['message' => 'Esta proposta não está disponível para assinatura.'], 422);
        }
        if ($me->signed_at) return response()->json(['data' => ['ja_assinou' => true]]);
        // P-E.2.4 — o assinante preenche os próprios dados (nome completo + CPF) no modal do portal.
        $v = $request->validate(['nome' => 'nullable|string|max:160', 'cpf' => 'nullable|string|max:20']);
        if (!empty(trim((string) ($v['nome'] ?? '')))) $me->name = trim($v['nome']);
        if (array_key_exists('cpf', $v) && $v['cpf'] !== null) $me->sign_cpf = preg_replace('/\D/', '', (string) $v['cpf']) ?: null;
        if ($me->isDirty()) $me->save();
        if (count(array_filter(preg_split('/\s+/', trim((string) $me->name)))) < 2) {
            return response()->json(['message' => 'Informe seu nome completo (nome e sobrenome) para assinar.', 'code' => 'NOME_INCOMPLETO'], 422);
        }
        // Bloqueia só se houver OUTRO aprovador pendente (governança multi-parte).
        $outros = $p->participants()->where('is_active', true)->get()
            ->filter(fn ($x) => $x->hasRole('approver') && !$x->approved_at && $x->id !== $me->id);
        if ($outros->isNotEmpty()) {
            return response()->json(['message' => 'Aguardando aprovação de: ' . $outros->pluck('name')->implode(', ') . '.', 'code' => 'APROVACAO_PENDENTE'], 422);
        }
        // Se o próprio assinante também é aprovador pendente, registra a aprovação (assinar pressupõe aprovar).
        if ($me->hasRole('approver') && !$me->approved_at) {
            try { app(\App\Services\ProposalParticipantService::class)->aprovar($me, $request->ip(), $request->userAgent(), 'Aprovado ao iniciar a assinatura'); $me->refresh(); $p->refresh(); }
            catch (\Throwable $e) { /* segue p/ assinatura mesmo se a marcação falhar */ }
        }

        $env = \App\Models\ClicksignEnvelope::with('signers')->where('crm_proposal_id', $p->id)->where('is_active', true)->orderByDesc('id')->first();
        if (!$env) {
            // Cria o envelope sob demanda (Portal → Clicksign). Remetente: vendedor → criador → 1º admin.
            $sender = $p->vendedor ?: ($p->created_by_id ? \App\Models\User::find($p->created_by_id) : null) ?: \App\Models\User::where('type', 'admin')->first();
            if (!$sender) return response()->json(['message' => 'Não foi possível identificar o remetente da assinatura. Contate o time comercial.'], 422);
            if (!$p->document_id) return response()->json(['message' => 'A proposta ainda não tem PDF gerado. Contate o time comercial.'], 422);
            // Só inclui no envelope quem AINDA NÃO assinou (quem já assinou — inclusive nativo — não reassina).
            $signers = $p->participants()->where('is_active', true)->get()->filter(fn ($x) => $x->hasRole('signer') && !$x->signed_at)
                ->map(fn ($x) => ['name' => $x->name, 'email' => $x->email, 'documentation' => $x->sign_cpf, 'crm_proposal_participant_id' => $x->id])
                ->values()->all();
            try {
                $env = app(\App\Services\Clicksign\ClicksignService::class)->enviarProposta($p, $signers, ['subject' => 'Assinatura — Proposta ' . ($p->codigo ?: $p->id)], $sender);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Falha ao iniciar a assinatura: ' . $e->getMessage()], 422);
            }
            $p->update(['status' => 'aguardando_assinatura']);
        } else {
            // Envelope já existia → reenvia a notificação (e-mail) para o assinante não ficar sem o link.
            try { app(\App\Services\Clicksign\ClicksignService::class)->reenviarNotificacao($env); } catch (\Throwable $e) { /* segue */ }
        }
        $signer = $env->signers->firstWhere('crm_proposal_participant_id', $me->id);
        $url = $signer?->sign_url;
        // v3: sem URL p/ iframe → o Clicksign enviou o link de assinatura (com o código) para o e-mail do assinante.
        if (!$url) return response()->json(['data' => ['por_email' => true, 'email' => $me->email, 'stub' => app(\App\Services\Clicksign\ClicksignService::class)->usandoStub()]]);
        return response()->json(['data' => ['sign_url' => $url, 'stub' => app(\App\Services\Clicksign\ClicksignService::class)->usandoStub()]]);
    }

    /**
     * P-E.2.4 — POST /p/{token}/sincronizar: o cliente clica "Baixar proposta assinada" → confirma no Clicksign,
     * regenera o PDF com a página de registro e devolve o status. (Atualiza a tela do portal.)
     */
    public function sincronizar(Request $request, string $token): JsonResponse
    {
        $share = $this->resolveShare($token);
        $r = app(\App\Documents\CrmProposalService::class)->sincronizarClicksign($share->proposal);
        return response()->json(['data' => [
            'ok' => $r['ok'] ?? false,
            'assinada' => $r['assinada'] ?? false,
            'status' => $r['status'] ?? $share->proposal->status,
            'mensagem' => ($r['ok'] ?? false) ? null : ($r['erro'] ?? null),
        ]]);
    }

    /** POST /p/{token}/reject — recusa (com motivo). */
    public function reject(Request $request, string $token): JsonResponse
    {
        $request->validate(['motivo' => 'nullable|string|max:1000']);
        $share = $this->resolveShare($token);
        abort_if(!$share->isAtivo(), 410, 'Este link não está mais disponível.');
        if ($share->accepted_at || $share->rejected_at) {
            return response()->json(['message' => 'Esta proposta já foi respondida.'], 409);
        }
        $p = $share->proposal;
        $motivo = trim((string) $request->input('motivo'));
        $share->rejected_at = now();
        $share->reject_reason = $motivo ?: null;
        $share->save();
        $p->update(['status' => 'reprovada']);
        $share->document?->update(['status' => 'reprovada']);
        $share->document?->logEvent(DocumentEvent::TYPE_RECUSADO, ['share_id' => $share->id, 'motivo' => $motivo], null);
        $this->marco($p, "Proposta {$p->codigo} RECUSADA" . ($motivo ? " — {$motivo}" : ''), 'proposta_recusada', $share->id);
        $this->followUp($p, 'retorno', "Proposta {$p->codigo} recusada — tratar", $motivo ? "Motivo informado pelo cliente: {$motivo}" : 'Cliente recusou pelo Portal (sem motivo informado).');
        return response()->json(['data' => ['decisao' => 'recusada']]);
    }

    // ───────────────────────── P-C.2 — Comentários e Revisões por Seção ─────────────────────────

    /** GET /p/{token}/secao/{key}/threads — lista threads + mensagens da seção (leitura liberada p/ o link). */
    public function threads(Request $request, string $token, string $key): JsonResponse
    {
        $share = $this->resolveShare($token);
        $p = $share->proposal;
        $secao = $p->sections()->where('section_key', $key)->first();
        abort_if(!$secao, 404, 'Seção não encontrada.');
        return response()->json(['data' => app(\App\Services\ProposalReviewService::class)->forSection($p, $key)]);
    }

    /** POST /p/{token}/secao/{key}/threads — abre uma thread (Reviewer/Approver). */
    public function abrirThread(Request $request, string $token, string $key): JsonResponse
    {
        $share = $this->resolveShare($token);
        $me = $this->exigeParticipante($share, $request);
        if (!($me->hasRole('reviewer') || $me->hasRole('approver'))) {
            return response()->json(['message' => 'Apenas Revisor ou Aprovador podem abrir uma revisão.'], 403);
        }
        $v = $request->validate(['subject' => 'required|string|max:200', 'message' => 'required|string|max:4000']);
        if (!in_array($key, \App\Services\ProposalSectionService::COMMENTABLE, true)) {
            return response()->json(['message' => 'Comentários são permitidos apenas em Escopo, Investimento e Prazo de Pagamento.'], 422);
        }
        $secao = $share->proposal->sections()->where('section_key', $key)->first();
        abort_if(!$secao, 404, 'Seção não encontrada.');
        $thread = app(\App\Services\ProposalReviewService::class)->abrirThread($share->proposal, $secao, $me, $v['subject'], $v['message']);
        return response()->json(['data' => app(\App\Services\ProposalReviewService::class)->serializeThread($thread->fresh(['messages.participant', 'messages.authorUser', 'author']))], 201);
    }

    /** POST /p/{token}/threads/{thread}/mensagens — responde a thread (Reviewer/Approver/Signer). */
    public function comentar(Request $request, string $token, \App\Models\CrmProposalReviewThread $thread): JsonResponse
    {
        $share = $this->resolveShare($token);
        abort_unless($thread->crm_proposal_id === $share->proposal_id, 404);
        $me = $this->exigeParticipante($share, $request);
        if (!($me->hasRole('reviewer') || $me->hasRole('approver') || $me->hasRole('signer'))) {
            return response()->json(['message' => 'Você não tem permissão para comentar.'], 403);
        }
        $v = $request->validate(['message' => 'required|string|max:4000']);
        app(\App\Services\ProposalReviewService::class)->mensagem($thread, $me, null, $v['message']);
        return response()->json(['data' => app(\App\Services\ProposalReviewService::class)->serializeThread($thread->fresh(['messages.participant', 'messages.authorUser', 'author']))]);
    }

    /** POST /p/{token}/threads/{thread}/resolver — resolve a thread (Reviewer/Approver ou quem abriu). */
    public function resolverThread(Request $request, string $token, \App\Models\CrmProposalReviewThread $thread): JsonResponse
    {
        $share = $this->resolveShare($token);
        abort_unless($thread->crm_proposal_id === $share->proposal_id, 404);
        $me = $this->exigeParticipante($share, $request);
        $dono = $thread->created_by_participant_id === $me->id;
        if (!($me->hasRole('reviewer') || $me->hasRole('approver') || $dono)) {
            return response()->json(['message' => 'Você não tem permissão para resolver esta revisão.'], 403);
        }
        app(\App\Services\ProposalReviewService::class)->resolver($thread, $me, null);
        return response()->json(['data' => ['status' => 'resolvida', 'proposta_status' => $share->proposal->fresh()->status]]);
    }

    // ───────────────────── P-E.1.1 — Leitura real do PDF (deck HTML + tracking por página) ─────────────────────

    /** GET /p/{token}/deck-html — deck pixel-fiel (mesma fonte do PDF) + tracker de página injetado. */
    public function deckHtml(Request $request, string $token)
    {
        $share = $this->resolveShare($token);
        abort_if($share->isRevogado(), 410, 'Este link foi revogado.');
        // Mesmo blade do PDF, com assets EMBUTIDOS (datauri) p/ ser self-contained no Portal público.
        $data = app(\App\Documents\CrmProposalService::class)->buildRenderData($share->proposal, 'datauri');
        $html = view('pdf.documents.proposta.render', [
            'slides' => $data['slides'], 'overlays' => $data['overlays'], 'codigo' => $share->proposal->codigo,
            'escopoIndex' => $data['escopoIndex'] ?? null, 'escopoPage' => $data['escopoPage'] ?? null,
            'aceiteIndex' => $data['aceiteIndex'] ?? null, 'aceitePage' => $data['aceitePage'] ?? null,
            'paginasOff' => $data['paginasOff'] ?? [],
            'manifesto' => $data['manifesto'] ?? [], // página de registro de assinaturas na prévia do portal
        ])->render();
        $tracker = $this->deckTrackerScript();
        $html = str_contains($html, '</body>') ? str_replace('</body>', $tracker . '</body>', $html) : $html . $tracker;
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, max-age=60']);
    }

    /** Script injetado no deck (roda dentro do iframe): observa cada .slide e posta eventos de página ao parent. */
    private function deckTrackerScript(): string
    {
        return <<<'JS'
<script>(function(){
  // Ajuste à largura: os slides são 1280px fixos; escala p/ caber na largura do iframe.
  function fit(){ try{ var w=(document.documentElement.clientWidth||window.innerWidth); var z=Math.min(1, w/1280); document.body.style.zoom=z; }catch(e){} }
  fit(); window.addEventListener('resize',fit); setTimeout(fit,200); setTimeout(fit,800);
  var slides=[].slice.call(document.querySelectorAll('.slide')); var total=slides.length;
  slides.forEach(function(s,i){ s.setAttribute('data-page', i+1); });
  function post(m){ try{ m.__deck=1; m.total=total; parent.postMessage(m,'*'); }catch(e){} }
  post({type:'pdf_aberto'});
  var cur=null, since=Date.now();
  function enter(p){ if(cur===p)return; if(cur!=null) post({type:'pagina_saida',page:cur,duration_seconds:Math.round((Date.now()-since)/1000)}); cur=p; since=Date.now(); post({type:'pagina_visualizada',page:p}); }
  var io=new IntersectionObserver(function(es){ var v=es.filter(function(e){return e.isIntersecting;}).sort(function(a,b){return b.intersectionRatio-a.intersectionRatio;}); if(v[0]) enter(parseInt(v[0].target.getAttribute('data-page'),10)); },{threshold:[0,.25,.5,.75]});
  slides.forEach(function(s){ io.observe(s); });
  function flush(){ if(cur!=null){ post({type:'pagina_saida',page:cur,duration_seconds:Math.round((Date.now()-since)/1000)}); post({type:'pdf_fechado',page:cur}); cur=null; } }
  window.addEventListener('pagehide',flush);
  document.addEventListener('visibilitychange',function(){ if(document.visibilityState==='hidden') flush(); });
  window.addEventListener('message',function(ev){ var d=ev.data||{}; if(d.__deckCmd==='goto'&&d.page){ var el=document.querySelector('.slide[data-page="'+d.page+'"]'); if(el) el.scrollIntoView({behavior:'smooth',block:'start'}); } });
})();</script>
JS;
    }

    /** POST /p/{token}/pagina — registra leitura por página (pdf_aberto / pagina_visualizada / pagina_saida / pdf_fechado). */
    public function registrarPagina(Request $request, string $token): JsonResponse
    {
        $share = $this->resolveShare($token);
        if (!$share->isAtivo()) return response()->json(['ok' => false]);
        $v = $request->validate([
            'action'           => 'required|in:pdf_aberto,pagina_visualizada,pagina_saida,pdf_fechado',
            'page'             => 'nullable|integer|min:1|max:300',
            'total_pages'      => 'nullable|integer|min:1|max:300',
            'duration_seconds' => 'nullable|integer|min:0|max:7200',
        ]);
        $me = $this->resolveParticipant($share, $request);
        $p  = $share->proposal;
        $action = $v['action'];

        if ($action === 'pdf_aberto') {
            $primeira = !\App\Models\CrmProposalPageView::where('crm_proposal_share_id', $share->id)->exists();
            if ($primeira && $p->opportunity_id) {
                CrmOpportunityEvent::log((int) $p->opportunity_id, 'note', [
                    'to_value' => "Cliente abriu o PDF da proposta {$p->codigo}" . ($me ? " ({$me->name})" : ''),
                    'triggered_by' => null, 'meta' => ['kind' => 'pdf_aberto', 'share_id' => $share->id, 'participant_id' => $me?->id],
                ]);
            }
            return response()->json(['ok' => true]);
        }

        $page = (int) ($v['page'] ?? 0);
        if ($page < 1) return response()->json(['ok' => false]);
        $dur = (int) ($v['duration_seconds'] ?? 0);

        if ($action === 'pagina_visualizada') {
            \App\Models\CrmProposalPageView::create([
                'crm_proposal_id' => $p->id, 'crm_proposal_share_id' => $share->id,
                'crm_proposal_participant_id' => $me?->id, 'page' => $page,
                'total_pages' => $v['total_pages'] ?? null, 'entered_at' => now(),
            ]);
        } else { // pagina_saida | pdf_fechado → fecha a visita aberta da página
            $aberta = \App\Models\CrmProposalPageView::where('crm_proposal_share_id', $share->id)
                ->where('page', $page)->whereNull('exited_at')->orderByDesc('id')->first();
            if ($aberta) {
                $aberta->update(['exited_at' => now(), 'duration_seconds' => $dur]);
            } else {
                \App\Models\CrmProposalPageView::create([
                    'crm_proposal_id' => $p->id, 'crm_proposal_share_id' => $share->id,
                    'crm_proposal_participant_id' => $me?->id, 'page' => $page, 'total_pages' => $v['total_pages'] ?? null,
                    'entered_at' => now()->subSeconds($dur), 'exited_at' => now(), 'duration_seconds' => $dur,
                ]);
            }
        }
        return response()->json(['ok' => true]);
    }

    /** Marco na timeline da oportunidade (não polui com revisitas). */
    private function marco($proposal, string $label, string $kind, int $shareId): void
    {
        if (!$proposal?->opportunity_id) return;
        CrmOpportunityEvent::log((int) $proposal->opportunity_id, 'note', [
            'to_value'     => $label,
            'triggered_by' => null,
            'meta'         => ['kind' => $kind, 'share_id' => $shareId],
        ]);
    }

    /** Tarefa de follow-up p/ o vendedor (notificação in-app). Idempotente por título+oportunidade. */
    private function followUp($proposal, string $categoria, string $titulo, string $notas): void
    {
        if (!$proposal?->opportunity_id) return;
        $resp = $proposal->vendedor_id ?? optional($proposal->opportunity)->responsavel_id;
        $existe = CrmTask::where('opportunity_id', $proposal->opportunity_id)
            ->where('titulo', $titulo)->whereNull('concluida_at')->exists();
        if ($existe) return;
        CrmTask::create([
            'customer_id'    => $proposal->customer_id,
            'opportunity_id' => $proposal->opportunity_id,
            'tipo'           => 'email',
            'categoria'      => $categoria,
            'titulo'         => $titulo,
            'data'           => now(),
            'responsavel_id' => $resp,
            'prioridade'     => 'alta',
            'notas'          => $notas,
            'created_by_id'  => $resp,
        ]);
    }
}
