<?php

namespace App\Services;

use App\Models\CrmProposal;
use App\Models\CrmProposalParticipant;
use App\Models\CrmProposalReviewMessage;
use App\Models\CrmProposalReviewThread;
use App\Models\CrmProposalSectionView;
use Carbon\Carbon;

/**
 * P-E.1 — Analytics OPERACIONAIS (sobre dados já capturados: shares, section_views, threads,
 * messages, participantes, aprovações/assinaturas, timeline). Sem dashboards de vaidade:
 * o foco é responder, ao abrir uma proposta: o cliente analisou? o que trava? quem age? há quanto tempo parou?
 */
class ProposalAnalyticsService
{
    private const FASES_ANALISE = ['enviada', 'em_analise', 'em_negociacao', 'em_revisao'];

    public function operational(CrmProposal $p): array
    {
        $p->loadMissing(['participants', 'sections', 'shares']);
        $shares = $p->shares;
        $enviada = $shares->whereNotNull('sent_at')->min('sent_at');
        $enviadaC = $enviada ? Carbon::parse($enviada) : null;

        $titulos = $p->sections->pluck('title', 'section_key');
        $views   = CrmProposalSectionView::where('crm_proposal_id', $p->id)->get();
        $threads = CrmProposalReviewThread::where('crm_proposal_id', $p->id)->get();
        $parts   = $p->participants->where('is_active', true);

        $diagnostico = $this->diagnostico($p, $shares, $views, $threads, $parts, $enviadaC, $titulos);
        $resumo      = $this->resumoAcionavel($p, $shares, $views, $threads, $parts, $enviadaC, $titulos);
        $leitura     = $this->paginas($p);
        $scoreEng    = $this->scoreEngajamento($p, $shares, $views, $threads, $parts);
        $situacao    = $this->situacao($p, $shares, $views, $threads, $parts, $resumo, $diagnostico, $leitura);

        return [
            'status'      => $p->status,
            'versao'      => (int) ($p->versao ?: 1),
            'temperatura' => $this->temperatura($scoreEng['score'], $situacao['nivel'], $resumo['dias_parada'] ?? null),
            'urgencia'    => $this->urgencia($resumo['ultima_atividade_em'] ?? null),
            'pendentes'   => $this->pendentesNominais($p, $parts),
            'assinatura'  => $this->assinaturaPorParte($p, $parts),
            'fluxo'       => $this->fluxo($p, $parts),
            'proximo_marco' => $this->proximoMarco($p, $parts, $shares),
            'situacao'    => $situacao,
            'engajamento' => $this->engajamento($p, $shares, $views, $threads, $parts, $enviadaC),
            'navegacao'   => $this->navegacao($views, $titulos),
            'revisoes'    => $this->revisoes($threads, $titulos),
            'aprovacao'   => $this->aprovacao($p, $parts, $enviadaC),
            'leitura_pdf' => $leitura,
            'participantes' => $this->participantesIndividual($p, $parts, $threads),
            'score_engajamento' => $scoreEng,
            'score_prontidao'   => $this->scoreProntidao($p, $parts, $threads),
            'diagnostico' => $diagnostico,
            'resumo'      => $resumo,
        ];
    }

    /** Movimentação da negociação pela última interação: 🟢 Ativa (≤24h) · 🟡 Desacelerando (2-5d) · 🔴 Parada (>5d). */
    private function urgencia(?string $ultimaIso): array
    {
        if (!$ultimaIso) return ['nivel' => 'vermelho', 'label' => 'Sem interação'];
        $h = Carbon::parse($ultimaIso)->diffInHours(now());
        if ($h <= 24) return ['nivel' => 'verde', 'label' => 'Ativa'];
        if ($h <= 120) return ['nivel' => 'amarelo', 'label' => 'Desacelerando'];
        return ['nivel' => 'vermelho', 'label' => 'Parada'];
    }

    /**
     * P-E.2.2 — assinatura concluída segundo o modo da proposta:
     *  - 'todos' (padrão): todos os signatários ativos assinaram.
     *  - 'um_por_parte': cada parte com signatário tem ao menos uma assinatura.
     */
    private function assinaturaOk(CrmProposal $p, $signers): bool
    {
        if ($signers->isEmpty()) return false;
        if (($p->assinatura_modo ?? 'todos') === 'um_por_parte') {
            foreach ($signers->groupBy(fn ($x) => $x->parte ?: 'indefinida') as $grupo) {
                if (!$grupo->contains(fn ($x) => $x->signed_at !== null)) return false;
            }
            return true;
        }
        return !$signers->contains(fn ($x) => $x->signed_at === null);
    }

    /** Assinatura agrupada por parte (Contratada/Contratante) para o painel de engajamento. */
    private function assinaturaPorParte(CrmProposal $p, $parts): array
    {
        $signers = $parts->filter(fn ($x) => $x->hasRole('signer'));
        $modo = $p->assinatura_modo ?? 'todos';
        $rotulo = ['contratada' => 'Contratada', 'contratante' => 'Contratante', 'indefinida' => 'Parte não definida'];
        $grupos = [];
        foreach (['contratada', 'contratante', 'indefinida'] as $key) {
            $g = $signers->filter(fn ($x) => ($x->parte ?: 'indefinida') === $key);
            if ($g->isEmpty()) continue;
            $assinados = $g->filter(fn ($x) => $x->signed_at !== null);
            $grupos[] = [
                'parte' => $key,
                'label' => $rotulo[$key],
                'assinantes' => $g->map(fn ($x) => [
                    'id' => $x->id, 'nome' => $x->name, 'email' => $x->email, 'cargo' => $x->cargo,
                    'assinado' => $x->signed_at !== null, 'assinado_em' => optional($x->signed_at)->toIso8601String(),
                    'status' => $x->sign_status, 'motivo' => $x->sign_refusal_reason,
                ])->values()->all(),
                'total' => $g->count(),
                'assinados' => $assinados->count(),
                // No modo um_por_parte a parte está OK com ≥1 assinatura; no modo todos exige todas.
                'ok' => $modo === 'um_por_parte' ? $assinados->isNotEmpty() : $assinados->count() === $g->count(),
            ];
        }
        $bloqueantes = [];
        foreach ($grupos as $grp) { if (!$grp['ok']) foreach ($grp['assinantes'] as $a) if (!$a['assinado']) $bloqueantes[] = ['nome' => $a['nome'], 'email' => $a['email'], 'parte' => $grp['label']]; }
        return [
            'modo' => $modo,
            'modo_label' => $modo === 'um_por_parte' ? 'Pelo menos um assinante por parte' : 'Todos devem assinar',
            'grupos' => $grupos,
            'completa' => $this->assinaturaOk($p, $signers),
            'sem_assinante' => $signers->isEmpty(),
            'pendentes_bloqueantes' => $bloqueantes,
        ];
    }

    /**
     * P-E.2.3 — APROVAÇÃO É OPCIONAL: o fluxo se adapta à existência de aprovadores.
     *  - tem aprovador → exige aprovação antes da assinatura;
     *  - sem aprovador → segue direto para assinatura (renovação, aditivo, venda fechada em reunião).
     */
    private function temAprovador($parts): bool
    {
        return $parts->contains(fn ($x) => $x->hasRole('approver'));
    }

    /** Aprovação satisfeita = não há aprovador (dispensada) OU todos os aprovadores aprovaram. */
    private function aprovacaoOk($parts): bool
    {
        $a = $parts->filter(fn ($x) => $x->hasRole('approver'));
        return $a->isEmpty() || $a->every(fn ($x) => $x->approved_at !== null);
    }

    /** Próximo MARCO da jornada (estágio, não ação). */
    private function proximoMarco(CrmProposal $p, $parts, $shares): ?array
    {
        if ($p->status === 'convertida') return null;
        if (in_array($p->status, ['reprovada', 'cancelada', 'expirada'], true)) return null;
        // Ainda não enviada → o próximo marco é ENVIAR ao cliente (não assinar).
        if (!$this->foiEnviada($p, $shares)) return ['icon' => '✉️', 'label' => 'Envio da proposta ao cliente'];
        $signers = $parts->filter(fn ($x) => $x->hasRole('signer'));
        $temAprov = $this->temAprovador($parts);
        $okAprov  = $this->aprovacaoOk($parts);
        $okAssin  = $this->assinaturaOk($p, $signers);

        if ($p->status === 'liberada') return ['icon' => '📦', 'label' => 'Conversão para Contrato Operacional'];
        if ($p->status === 'assinada' || ($okAprov && $okAssin)) return ['icon' => '🚀', 'label' => 'Liberação para Serviços'];
        if ($temAprov && !$okAprov) return ['icon' => '📋', 'label' => 'Aprovação da proposta'];
        if (!$okAssin) return ['icon' => '✍️', 'label' => 'Assinatura da proposta'];
        return ['icon' => '🚀', 'label' => 'Liberação para Serviços'];
    }

    /**
     * P-E.2.3 — descreve o FLUXO automático (com/sem aprovação) e a AÇÃO PRIMÁRIA recomendada.
     * `acao_primaria.codigo` orienta o botão principal do editor.
     */
    private function fluxo(CrmProposal $p, $parts): array
    {
        $signers  = $parts->filter(fn ($x) => $x->hasRole('signer'));
        $pendAprov = $parts->filter(fn ($x) => $x->hasRole('approver') && !$x->approved_at);
        $temAprov = $this->temAprovador($parts);
        $okAprov  = $this->aprovacaoOk($parts);
        $okAssin  = $this->assinaturaOk($p, $signers);
        $st = $p->status;

        $acao = ['codigo' => 'nenhuma', 'label' => 'Nenhuma ação no momento', 'icon' => '✅'];
        $etapa = 'concluida';
        if (in_array($st, ['convertida', 'reprovada', 'cancelada', 'expirada'], true)) {
            $etapa = 'encerrada';
        } elseif ($st === 'liberada') {
            $etapa = 'conversao'; $acao = ['codigo' => 'converter', 'label' => '📦 Gerar Contrato', 'icon' => '📦'];
        } elseif ($st === 'assinada') {
            $etapa = 'liberacao'; $acao = ['codigo' => 'liberar', 'label' => '🚀 Liberar para Serviços', 'icon' => '🚀'];
        } elseif ($temAprov && !$okAprov) {
            $etapa = 'aprovacao'; $acao = ['codigo' => 'solicitar_aprovacao', 'label' => '📋 Solicitar Aprovação', 'icon' => '📋'];
        } elseif ($signers->isEmpty()) {
            $etapa = 'assinatura'; $acao = ['codigo' => 'definir_assinantes', 'label' => '👥 Definir Assinantes', 'icon' => '👥'];
        } elseif ($st === 'aguardando_assinatura' && !$okAssin) {
            $etapa = 'assinatura'; $acao = ['codigo' => 'aguardando_assinatura', 'label' => '⏳ Aguardando Assinatura', 'icon' => '⏳'];
        } elseif (!$okAssin) {
            $etapa = 'assinatura'; $acao = ['codigo' => 'enviar_assinatura', 'label' => '✍ Enviar para Assinatura', 'icon' => '✍'];
        }

        return [
            'tem_aprovacao'  => $temAprov,
            'aprovacao_ok'   => $okAprov,
            'assinatura_ok'  => $okAssin,
            'etapa'          => $etapa,
            'acao_primaria'  => $acao,
            'descricao'      => $temAprov
                ? 'Fluxo com aprovação: Proposta → Aprovação → Assinatura → Contrato.'
                : 'Fluxo direto: Proposta → Assinatura → Contrato (sem aprovação formal).',
            'pendentes_aprovacao' => $pendAprov->pluck('name')->values()->all(),
        ];
    }

    /** Quem está pendente (nominal): aprovação e assinatura, alinhado ao "o que falta". */
    private function pendentesNominais(CrmProposal $p, $parts): array
    {
        $approvers = $parts->filter(fn ($x) => $x->hasRole('approver'));
        $signers   = $parts->filter(fn ($x) => $x->hasRole('signer'));
        $aprov = $approvers->filter(fn ($x) => !$x->approved_at)->pluck('name')->values()->all();
        $assin = $signers->filter(fn ($x) => !$x->signed_at)->pluck('name')->values()->all();
        $okAprov = $this->aprovacaoOk($parts);
        $okAssin = $this->assinaturaOk($p, $signers);
        return [
            'aprovacao' => $aprov,
            'assinatura' => $assin,
            'sem_aprovador' => $approvers->isEmpty(),
            'aprovacao_dispensada' => $approvers->isEmpty(), // P-E.2.3 — sem aprovador = fluxo direto (não é erro)
            'sem_assinante' => $signers->isEmpty(),
            'todos_concluiram' => $okAprov && $okAssin,
        ];
    }

    /** Temperatura da Oportunidade — leitura instantânea (Quente/Morna/Fria). */
    private function temperatura(int $scoreEng, string $nivelSituacao, ?int $diasParada): array
    {
        if ($nivelSituacao === 'vermelho' || ($diasParada !== null && $diasParada >= 7)) return ['nivel' => 'fria', 'label' => 'Fria'];
        if ($scoreEng > 70 && ($diasParada === null || $diasParada < 3)) return ['nivel' => 'quente', 'label' => 'Quente'];
        if ($scoreEng >= 40) return ['nivel' => 'morna', 'label' => 'Morna'];
        return ['nivel' => 'fria', 'label' => 'Fria'];
    }

    /** BLOCO 1 — Diagnóstico executivo: UMA conclusão (cor + título + descrição + próxima ação). */
    /** A proposta foi REALMENTE enviada ao cliente? (link/share com sent_at OU fluxo de assinatura outbound).
     *  NÃO confia no status manual "enviada" — o status pode ter sido mudado à mão sem gerar/enviar link. */
    private function foiEnviada(CrmProposal $p, $shares): bool
    {
        return $shares->whereNotNull('sent_at')->isNotEmpty()
            || in_array($p->status, ['aguardando_assinatura', 'assinada', 'liberada', 'convertida'], true);
    }

    private function situacao(CrmProposal $p, $shares, $views, $threads, $parts, array $resumo, array $diagnostico, array $leitura): array
    {
        $abriu = $resumo['cliente_analisou'] !== 'nao_abriu';
        $dias = $resumo['dias_parada'];
        $pend = $threads->whereIn('status', ['aberta', 'respondida']);
        $pendAprov = $parts->filter(fn ($x) => $x->hasRole('approver') && !$x->approved_at);
        $pendAssin = $parts->filter(fn ($x) => $x->hasRole('signer') && !$x->signed_at);
        $chegouInvest = $leitura['disponivel'] ?? false ? ($leitura['diagnostico']['chegou_investimento'] ?? false) : null;

        $nivel = 'amarelo'; $titulo = 'Em andamento'; $desc = '';

        if ($p->status === 'convertida') { $nivel = 'verde'; $titulo = 'Convertida'; $desc = 'Proposta convertida em contrato operacional.'; }
        elseif (in_array($p->status, ['reprovada', 'cancelada', 'expirada'], true)) { $nivel = 'vermelho'; $titulo = 'Encerrada'; $desc = 'Proposta encerrada sem fechamento.'; }
        elseif (!$this->foiEnviada($p, $shares)) { $nivel = 'amarelo'; $titulo = 'Aguardando Envio'; $desc = 'Proposta ainda não enviada ao cliente — gere o link e envie.'; }
        elseif (!$abriu && $dias !== null && $dias >= 5) { $nivel = 'vermelho'; $titulo = 'Risco de Perda'; $desc = "Cliente ainda não abriu a proposta há {$dias} dia(s)."; }
        elseif (!$abriu) { $nivel = 'amarelo'; $titulo = 'Aguardando Cliente'; $desc = 'Proposta enviada; cliente ainda não abriu.'; }
        elseif ($dias !== null && $dias >= 7) { $nivel = 'vermelho'; $titulo = 'Risco de Perda'; $desc = ($chegouInvest === false ? 'Cliente abandonou a análise antes do Investimento. ' : '') . "Sem interação há {$dias} dia(s)."; }
        elseif ($pend->isNotEmpty()) { $nivel = 'amarelo'; $titulo = 'Revisão Pendente'; $desc = "{$pend->count()} revisão(ões) pendente(s) — cliente aguarda retorno."; }
        else {
            // Estágio operacional real (P-E.1.4): não dar conclusão otimista sem pré-requisitos definidos.
            $signers   = $parts->filter(fn ($x) => $x->hasRole('signer'));
            $temAprov = $this->temAprovador($parts); $temAssin = $signers->isNotEmpty();
            $okAprov = $this->aprovacaoOk($parts); // P-E.2.3 — sem aprovador = aprovação dispensada
            $okAssin = $this->assinaturaOk($p, $signers);
            $baseLeitura = ($leitura['disponivel'] ?? false) ? rtrim($leitura['resumo_humano'], '.') : 'Cliente analisou a proposta';

            if ($p->status === 'liberada') {
                $nivel = 'verde'; $titulo = 'Liberada'; $desc = 'Liberada para Serviços — aguardando a criação do contrato operacional.';
            } elseif ($p->status === 'assinada' || ($okAprov && $okAssin)) {
                $nivel = 'verde'; $titulo = 'Pronta para Avançar'; $desc = 'Todos os participantes concluíram suas ações. A proposta pode ser liberada para Serviços.';
            } elseif ($okAprov && $temAssin && !$okAssin) {
                $nivel = 'verde'; $titulo = $temAprov ? 'Alta Intenção' : 'Pronta para Assinatura';
                $desc = $temAprov
                    ? 'A proposta foi aprovada e aguarda apenas a formalização da assinatura.'
                    : 'Sem aprovação formal necessária — pronta para enviar à assinatura.';
            } elseif ($temAprov && !$okAprov) {
                $nivel = 'amarelo'; $titulo = 'Aguardando Aprovação'; $desc = $baseLeitura . '. Aguardando a decisão dos aprovadores antes de seguir para a assinatura.';
            } elseif (!$temAssin) {
                $nivel = 'amarelo'; $titulo = 'Interesse Demonstrado'; $desc = $baseLeitura . '. Ainda não foram definidos os assinantes da proposta.';
            } else {
                $nivel = 'verde'; $titulo = 'Negociação Ativa'; $desc = $baseLeitura . '. O processo de decisão está em andamento com os participantes definidos.';
            }
        }

        return [
            'nivel' => $nivel, 'titulo' => $titulo, 'descricao' => trim($desc),
            'proxima_acao' => $diagnostico['proximo_passo'] ?? null,
            'ultima_interacao_em' => $resumo['ultima_atividade_em'] ?? null,
            'dias_parada' => $dias,
        ];
    }

    /** P-E.1.2 — Visão INDIVIDUAL por participante (engajamento + leitura + ações) + score por pessoa. */
    private function participantesIndividual(CrmProposal $p, $parts, $threads): array
    {
        $map = $this->pageMap($p);
        $pvByPart = \App\Models\CrmProposalPageView::where('crm_proposal_id', $p->id)
            ->whereNotNull('crm_proposal_participant_id')->get()->groupBy('crm_proposal_participant_id');
        $msgByPart = \App\Models\CrmProposalReviewMessage::whereIn('crm_proposal_review_thread_id', $threads->pluck('id'))
            ->whereNotNull('crm_proposal_participant_id')->get()->groupBy('crm_proposal_participant_id');
        $thByPart = $threads->groupBy('created_by_participant_id');
        $totalPag = $map['total'];

        return $parts->map(function ($x) use ($pvByPart, $msgByPart, $thByPart, $totalPag, $map) {
            $pv = $pvByPart->get($x->id) ?? collect();
            $pagDistintas = $pv->pluck('page')->unique()->count();
            $ultima = (int) ($pv->max('page') ?? 0);
            $pct = $totalPag ? (int) round($pagDistintas / $totalPag * 100) : 0;
            $d = [
                'id' => $x->id, 'nome' => $x->name, 'papeis' => $x->roles,
                'ultimo_acesso' => optional($x->last_access_at)->toIso8601String(),
                'total_acessos' => (int) $x->access_count,
                'tempo_leitura_seg' => (int) $pv->sum('duration_seconds'),
                'percentual_lido' => $pct,
                'ultima_pagina' => $ultima,
                'chegou_investimento' => $ultima >= $map['investimento'],
                'chegou_aceite' => $ultima >= $map['aceite'],
                'comentarios' => ($msgByPart->get($x->id)?->count() ?? 0),
                'revisoes_solicitadas' => ($thByPart->get($x->id)?->count() ?? 0),
                'aprovou' => $x->approved_at !== null,
                'assinou' => $x->signed_at !== null,
            ];
            $d['score'] = $this->scoreEngajamentoParticipante($x, $d);
            return $d;
        })->values()->all();
    }

    private function scoreEngajamentoParticipante($x, array $d): int
    {
        $s = 0;
        if ($x->viewed_at || $x->last_access_at || $d['ultima_pagina'] > 0) $s += 10; // abriu
        if ($d['total_acessos'] > 1) $s += 10;                                        // mais de uma visita
        if ($d['percentual_lido'] > 50) $s += 15;
        if ($d['percentual_lido'] > 80) $s += 10;
        if ($d['chegou_investimento']) $s += 15;
        if ($d['chegou_aceite']) $s += 15;
        if ($d['comentarios'] > 0) $s += 10;
        if ($d['revisoes_solicitadas'] > 0) $s += 10;
        if ($d['aprovou']) $s += 5;
        if ($d['assinou']) $s += 10;
        return min(100, $s);
    }

    /** Score de Engajamento (0-100) da PROPOSTA (consolidado). */
    private function scoreEngajamento(CrmProposal $p, $shares, $views, $threads, $parts): array
    {
        $map = $this->pageMap($p);
        $ultima = (int) (\App\Models\CrmProposalPageView::where('crm_proposal_id', $p->id)->max('page') ?? 0);
        $distintas = \App\Models\CrmProposalPageView::where('crm_proposal_id', $p->id)->distinct('page')->count('page');
        $pct = $map['total'] ? (int) round($distintas / $map['total'] * 100) : 0;
        $abriu = $shares->whereNotNull('first_viewed_at')->isNotEmpty() || $ultima > 0;
        $s = 0;
        if ($abriu) $s += 10;
        if ((int) $shares->sum('view_count') > 1) $s += 10;
        if ($pct > 50) $s += 15;
        if ($pct > 80) $s += 10;
        if ($ultima >= $map['investimento']) $s += 15;
        if ($ultima >= $map['aceite']) $s += 15;
        if ($threads->isNotEmpty()) $s += 10;                                   // comentou
        if ($threads->isNotEmpty()) $s += 10;                                   // solicitou revisão
        if ($parts->contains(fn ($x) => $x->approved_at)) $s += 5;
        if ($parts->contains(fn ($x) => $x->signed_at)) $s += 10;
        $score = min(100, $s);
        return ['score' => $score, 'classificacao' => $this->classEngajamento($score)];
    }

    /** Engajamento = intensidade de INTERAÇÃO (sem nomes de estágio comercial, que ficam no diagnóstico). */
    private function classEngajamento(int $s): string
    {
        if ($s <= 25) return 'Pouca interação';
        if ($s <= 50) return 'Cliente engajado';
        if ($s <= 75) return 'Interesse elevado';
        return 'Forte envolvimento';
    }

    /** Score de Prontidão Comercial (0-100) + composição (por que o número é esse). */
    private function scoreProntidao(CrmProposal $p, $parts, $threads): array
    {
        $map = $this->pageMap($p);
        $approvers = $parts->filter(fn ($x) => $x->hasRole('approver'));
        $signers   = $parts->filter(fn ($x) => $x->hasRole('signer'));
        $ultima = (int) (\App\Models\CrmProposalPageView::where('crm_proposal_id', $p->id)->max('page') ?? 0);

        $okAprov = $this->aprovacaoOk($parts); // P-E.2.3 — sem aprovador = etapa dispensada (não penaliza)
        $okAssin = $this->assinaturaOk($p, $signers);
        $okRev   = $threads->whereIn('status', ['aberta', 'respondida'])->isEmpty();
        $okAceite = $ultima >= $map['aceite'];

        $s = ($okAprov ? 30 : 0) + ($okAssin ? 40 : 0) + ($okRev ? 15 : 0) + ($okAceite ? 15 : 0);
        $score = min(100, $s);

        // Componentes com motivos CONTEXTUAIS (define o que realmente impede o avanço).
        $labelAprov = $approvers->isEmpty() ? 'Aprovação não exigida' : 'Aprovações concluídas';
        $faltaAprov = $approvers->isEmpty() ? '—' : 'Processo de aprovação não concluído';
        $faltaAssin = $signers->isEmpty() ? 'Nenhum assinante definido' : 'Assinaturas não concluídas';
        $componentes = [
            ['label' => $labelAprov, 'falta' => $faltaAprov, 'ok' => $okAprov, 'pontos' => 30],
            ['label' => 'Assinaturas concluídas', 'falta' => $faltaAssin, 'ok' => $okAssin, 'pontos' => 40],
            ['label' => 'Sem revisões pendentes', 'falta' => 'Revisões pendentes', 'ok' => $okRev, 'pontos' => 15],
            ['label' => 'Cliente chegou ao Aceite', 'falta' => 'Cliente não chegou ao Aceite', 'ok' => $okAceite, 'pontos' => 15],
        ];
        $pendencias = array_values(array_map(fn ($c) => $c['falta'], array_filter($componentes, fn ($c) => !$c['ok'])));

        return ['score' => $score, 'classificacao' => $this->classProntidao($score), 'componentes' => $componentes, 'pendencias' => $pendencias];
    }

    private function classProntidao(int $s): string
    {
        if ($s >= 100) return 'Liberada';
        if ($s <= 25) return 'Inicial';
        if ($s <= 50) return 'Em Preparação';
        if ($s <= 75) return 'Em Decisão';
        return 'Pronta para Fechamento';
    }

    /** Diagnóstico comercial automático: frases + próximo passo recomendado. */
    private function diagnostico(CrmProposal $p, $shares, $views, $threads, $parts, ?Carbon $enviadaC, $titulos): array
    {
        $map = $this->pageMap($p);
        $ultima = (int) (\App\Models\CrmProposalPageView::where('crm_proposal_id', $p->id)->max('page') ?? 0);
        $abriu = $shares->whereNotNull('first_viewed_at')->isNotEmpty() || $ultima > 0;
        $frases = [];

        $frases[] = $abriu ? 'Cliente analisou a proposta.' : 'Cliente ainda não abriu a proposta.';
        // página de maior atenção
        $top = \App\Models\CrmProposalPageView::where('crm_proposal_id', $p->id)
            ->selectRaw('page, sum(duration_seconds) as seg')->groupBy('page')->orderByDesc('seg')->first();
        if ($top) $frases[] = $this->pageLabel((int) $top->page, $map) . ' recebeu maior atenção.';

        $pendSigners = $parts->filter(fn ($x) => $x->hasRole('signer') && !$x->signed_at);
        if (in_array($p->status, ['aprovada', 'aguardando_assinatura'], true) && $pendSigners->isNotEmpty()) {
            $frases[] = $pendSigners->pluck('name')->implode(', ') . ' ainda não assinou.';
        }
        $pend = $threads->whereIn('status', ['aberta', 'respondida']);
        if ($pend->isNotEmpty()) {
            $secs = $pend->pluck('section_key')->unique()->map(fn ($k) => $titulos[$k] ?? $k)->implode(', ');
            $frases[] = "Existem {$pend->count()} revisão(ões) pendente(s) em {$secs}.";
        }
        $ultAtiv = collect([$shares->max('last_viewed_at'), $parts->max('last_access_at'), $threads->max('updated_at'), $enviadaC])
            ->filter()->map(fn ($d) => Carbon::parse($d))->max();
        $diasParada = $ultAtiv ? (int) $ultAtiv->diffInDays(now()) : null;
        if ($diasParada !== null) $frases[] = "Proposta parada há {$diasParada} dia(s).";

        // Próximo passo — DERIVADO das mesmas pendências do score de Prontidão (consistente), nunca genérico.
        $primeiroNome = fn ($c) => $c->pluck('name')->map(fn ($n) => explode(' ', trim($n))[0])->implode(', ');
        $approvers = $parts->filter(fn ($x) => $x->hasRole('approver'));
        $signers   = $parts->filter(fn ($x) => $x->hasRole('signer'));
        $pendApprovers = $approvers->filter(fn ($x) => !$x->approved_at);
        $okAprov = $this->aprovacaoOk($parts); // P-E.2.3 — sem aprovador = dispensada
        $okAssin = $this->assinaturaOk($p, $signers);
        // Pendentes que de fato BLOQUEIAM a conclusão (no modo um_por_parte, só partes sem nenhuma assinatura).
        $pendBloqueantes = ($p->assinatura_modo ?? 'todos') === 'um_por_parte'
            ? $pendSigners->filter(fn ($x) => !$signers->filter(fn ($y) => ($y->parte ?: 'i') === ($x->parte ?: 'i'))->contains(fn ($y) => $y->signed_at !== null))
            : $pendSigners;
        $okAceite = $ultima >= $map['aceite'];

        if (in_array($p->status, ['convertida', 'reprovada', 'cancelada', 'expirada'], true)) {
            $passo = '✅ Nenhuma ação necessária neste momento.';
        } elseif (!$this->foiEnviada($p, $shares)) {
            $passo = '✉ Gerar o link e enviar a proposta ao cliente.';
        } elseif (!$abriu) {
            $passo = $diasParada !== null && $diasParada >= 3 ? '📞 Ligar para o cliente — proposta enviada e ainda não aberta.' : '✉ Reenviar o link da proposta ao cliente.';
        } elseif ($pend->isNotEmpty()) {
            $passo = '💬 Tratar as revisões abertas pelo cliente e reenviar a versão.';
        } elseif (!$okAprov) {
            // Só cai aqui quando HÁ aprovadores pendentes (sem aprovador = okAprov true → fluxo direto).
            $passo = '📋 Solicitar aprovação de ' . $primeiroNome($pendApprovers);
        } elseif (!$okAssin) {
            if ($signers->isEmpty()) $passo = '👥 Definir assinantes';
            elseif ($p->status !== 'aguardando_assinatura') $passo = '✍ Enviar para assinatura';
            else $passo = '✉ Reenviar assinatura para ' . $primeiroNome($pendBloqueantes->isNotEmpty() ? $pendBloqueantes : $pendSigners);
        } elseif ($p->status === 'assinada') {
            $passo = '➜ Liberar a proposta para Serviços (handoff).';
        } elseif ($p->status === 'liberada') {
            $passo = '➜ Criar o contrato operacional no Kanban de Serviços.';
        } elseif (!$okAceite) {
            $passo = '📞 Fazer follow-up — o cliente ainda não chegou à seção de Aceite.';
        } elseif ($diasParada !== null && $diasParada >= 3) {
            $passo = '📞 Fazer follow-up comercial com o cliente.';
        } else {
            $passo = '✅ Nenhuma ação necessária neste momento.';
        }

        return ['frases' => $frases, 'proximo_passo' => $passo, 'dias_parada' => $diasParada];
    }

    private const SECTION_LABELS = [
        'resumo_executivo' => 'Resumo Executivo', 'escopo' => 'Escopo', 'premissas' => 'Premissas',
        'cronograma' => 'Cronograma', 'investimento' => 'Investimento', 'prazo' => 'Prazo de Pagamento',
        'observacoes' => 'Observações', 'aceite' => 'Aceite', 'anexos' => 'Anexos',
    ];

    /** P-E.1.2 §4 — Inteligência de conteúdo AGREGADA (rankings sobre todas as propostas). */
    public function conteudoAgregado(): array
    {
        $lbl = fn ($k) => self::SECTION_LABELS[$k] ?? $k;
        $maisRevisadas = \App\Models\CrmProposalReviewThread::selectRaw('section_key, count(*) as total')
            ->groupBy('section_key')->orderByDesc('total')->limit(8)->get()
            ->map(fn ($r) => ['secao' => $lbl($r->section_key), 'total' => (int) $r->total])->all();
        $maisLidas = \App\Models\CrmProposalSectionView::selectRaw('section_key, sum(duration_seconds) as seg')
            ->groupBy('section_key')->orderByDesc('seg')->limit(8)->get()
            ->map(fn ($r) => ['secao' => $lbl($r->section_key), 'segundos' => (int) $r->seg])->all();
        // Ignoradas: comentáveis com MENOR tempo de leitura (asc).
        $tempoPorSecao = \App\Models\CrmProposalSectionView::selectRaw('section_key, sum(duration_seconds) as seg')
            ->groupBy('section_key')->pluck('seg', 'section_key');
        $maisIgnoradas = collect(ProposalSectionService::COMMENTABLE)
            ->map(fn ($k) => ['secao' => $lbl($k), 'segundos' => (int) ($tempoPorSecao[$k] ?? 0)])
            ->sortBy('segundos')->values()->all();
        $paginasPermanencia = \App\Models\CrmProposalPageView::selectRaw('page, sum(duration_seconds) as seg')
            ->groupBy('page')->orderByDesc('seg')->limit(8)->get()
            ->map(fn ($r) => ['pagina' => (int) $r->page, 'segundos' => (int) $r->seg])->all();
        // Abandono: última página de cada sessão (share) → ranking de páginas onde mais se para.
        $ultimasPorShare = \App\Models\CrmProposalPageView::selectRaw('crm_proposal_share_id, max(page) as ult')
            ->whereNotNull('crm_proposal_share_id')->groupBy('crm_proposal_share_id')->get();
        $paginasAbandono = $ultimasPorShare->groupBy('ult')->map(fn ($g, $pg) => ['pagina' => (int) $pg, 'sessoes' => $g->count()])
            ->sortByDesc('sessoes')->values()->take(8)->all();

        return [
            'secoes_mais_revisadas' => $maisRevisadas,
            'secoes_maior_leitura'  => $maisLidas,
            'secoes_mais_ignoradas' => $maisIgnoradas,
            'paginas_maior_permanencia' => $paginasPermanencia,
            'paginas_maior_abandono' => $paginasAbandono,
        ];
    }

    /** Scores leves (engajamento + prontidão) por proposta p/ os badges do Kanban — batch sem N+1. */
    public function scoresBatch($proposals): array
    {
        $ids = collect($proposals)->pluck('id');
        if ($ids->isEmpty()) return [];
        $partsBy = \App\Models\CrmProposalParticipant::whereIn('crm_proposal_id', $ids)->where('is_active', true)->get()->groupBy('crm_proposal_id');
        $thBy = \App\Models\CrmProposalReviewThread::whereIn('crm_proposal_id', $ids)->get()->groupBy('crm_proposal_id');
        $pvBy = \App\Models\CrmProposalPageView::whereIn('crm_proposal_id', $ids)
            ->selectRaw('crm_proposal_id, max(page) as ult, count(distinct page) as distintas')->groupBy('crm_proposal_id')->get()->keyBy('crm_proposal_id');

        $out = [];
        foreach ($proposals as $p) {
            $map = $this->pageMap($p);
            $parts = $partsBy->get($p->id) ?? collect();
            $threads = $thBy->get($p->id) ?? collect();
            $pv = $pvBy->get($p->id);
            $ultima = (int) ($pv->ult ?? 0);
            $pct = ($map['total'] && $pv) ? (int) round(($pv->distintas) / $map['total'] * 100) : 0;

            // engajamento
            $e = 0;
            if ($ultima > 0) $e += 20;                       // abriu + visita
            if ($pct > 50) $e += 15;
            if ($pct > 80) $e += 10;
            if ($ultima >= $map['investimento']) $e += 15;
            if ($ultima >= $map['aceite']) $e += 15;
            if ($threads->isNotEmpty()) $e += 20;            // comentou/revisou
            if ($parts->contains(fn ($x) => $x->approved_at)) $e += 5;
            if ($parts->contains(fn ($x) => $x->signed_at)) $e += 10;
            $e = min(100, $e);

            // prontidão
            $signers = $parts->filter(fn ($x) => $x->hasRole('signer'));
            $r = 0;
            if ($this->aprovacaoOk($parts)) $r += 30; // P-E.2.3 — sem aprovador = dispensada
            if ($this->assinaturaOk($p, $signers)) $r += 40;
            if ($threads->whereIn('status', ['aberta', 'respondida'])->isEmpty()) $r += 15;
            if ($ultima >= $map['aceite']) $r += 15;
            $r = min(100, $r);

            $out[$p->id] = [
                'engajamento' => ['score' => $e, 'classificacao' => $this->classEngajamento($e)],
                'prontidao'   => ['score' => $r, 'classificacao' => $this->classProntidao($r)],
            ];
        }
        return $out;
    }

    /** Rótulos do deck por página (1-based) por tipo — para falar em CONTEÚDO, nunca "Página N". */
    private const DECK_LABELS = [
        'bh_fixo'   => ['Capa', 'O que resolvemos', 'Como resolvemos', 'Escopo', 'Processos de Projeto', 'Processos de Suporte', 'Investimento', 'Prazo e Pagamento', 'Aceite', 'Encerramento'],
        'bh_mensal' => ['Capa', 'O que resolvemos', 'Como resolvemos', 'Escopo', 'Processos de Projeto', 'Processos de Suporte', 'Investimento', 'Prazo e Pagamento', 'Aceite', 'Encerramento'],
        'on_demand' => ['Capa', 'O que resolvemos', 'Como resolvemos', 'Escopo', 'Processos de Projeto', 'Processos de Suporte', 'Investimento', 'Prazo e Pagamento', 'Aceite', 'Encerramento'],
        'projeto_fechado' => ['Capa', 'O que resolvemos', 'Como resolvemos', 'Escopo', 'Processos de Projeto', 'Investimento', 'Prazo e Pagamento', 'Aceite', 'Encerramento'],
    ];

    /** Mapa de páginas-chave do deck (1-based) por tipo + nº total de páginas + rótulos. */
    private function pageMap(CrmProposal $p): array
    {
        $tipo = $p->tipo ?: 'bh_fixo';
        $isPF = $tipo === 'projeto_fechado'; $isCloud = $tipo === 'cloud';
        $slideCount = $isCloud ? 13 : ($isPF ? 9 : 10);
        return [
            'tipo' => $tipo,
            'capa' => 1,
            'escopo' => ($isCloud ? 2 : 3) + 1,
            'investimento' => ($isCloud ? 9 : ($isPF ? 5 : 6)) + 1,
            'prazo' => ($isPF ? 6 : 7) + 1,
            'aceite' => $slideCount - 1,
            'total' => $slideCount,
            'labels' => self::DECK_LABELS[$tipo] ?? [],
        ];
    }

    /** Nome do CONTEÚDO da página (rótulo do deck → chave → fallback "Seção N"). */
    private function pageLabel(int $n, array $map): string
    {
        $labels = $map['labels'] ?? [];
        if (isset($labels[$n - 1]) && $labels[$n - 1] !== '') return $labels[$n - 1];
        foreach (['capa' => 'Capa', 'escopo' => 'Escopo', 'investimento' => 'Investimento', 'prazo' => 'Prazo e Pagamento', 'aceite' => 'Aceite'] as $k => $label) {
            if (($map[$k] ?? null) === $n) return $label;
        }
        return "Seção {$n}";
    }

    /** P-E.1.1 — Leitura real do PDF por página (leitura / interesse / abandono / diagnóstico). */
    private function paginas(CrmProposal $p): array
    {
        $map = $this->pageMap($p);
        $views = \App\Models\CrmProposalPageView::where('crm_proposal_id', $p->id)->get();
        if ($views->isEmpty()) {
            return ['disponivel' => false, 'total_paginas' => $map['total']];
        }
        $total = max($map['total'], (int) $views->max('total_pages'));
        $porPagina = $views->groupBy('page')->map(fn ($g, $pg) => [
            'page' => (int) $pg, 'label' => $this->pageLabel((int) $pg, $map),
            'segundos' => (int) $g->sum('duration_seconds'), 'visitas' => $g->count(),
        ])->sortByDesc('segundos')->values();

        $distintas = $porPagina->count();
        $ultima = (int) $views->max('page');
        // "Maior interesse" = só páginas de CONTEÚDO (exclui Capa, Aceite e Encerramento).
        $excluir = [1, $map['aceite'], $map['total']];
        $conteudoPgs = $porPagina->reject(fn ($x) => in_array($x['page'], $excluir, true))->filter(fn ($x) => $x['segundos'] > 0)->sortByDesc('segundos')->values();
        $maisInteresse = null; $interesseMsg = null;
        if ($conteudoPgs->count() === 0) {
            $interesseMsg = 'Dados insuficientes para determinar interesse predominante';
        } elseif ($conteudoPgs->count() === 1) {
            $maisInteresse = $conteudoPgs->first();
        } else {
            $top = $conteudoPgs->first(); $seg2 = (int) ($conteudoPgs->get(1)['segundos'] ?? 0);
            if ($top['segundos'] >= max(10, (int) round($seg2 * 1.4))) $maisInteresse = $top; // concentração clara
            else $interesseMsg = 'Leitura distribuída entre as seções';
        }
        $mais = $porPagina->first();
        $menos = $porPagina->sortBy('segundos')->first();
        $tempoMedio = $distintas ? (int) round($porPagina->sum('segundos') / $distintas) : 0;

        // Abandono: maior página alcançada por sessão (share) → ponto médio.
        $maxPorShare = $views->groupBy('crm_proposal_share_id')->map(fn ($g) => (int) $g->max('page'));
        $pontoMedio = $maxPorShare->isNotEmpty() ? (int) round($maxPorShare->avg()) : $ultima;
        // Participantes (identificados) que não chegaram ao Investimento.
        $naoInvest = $views->whereNotNull('crm_proposal_participant_id')->groupBy('crm_proposal_participant_id')
            ->filter(fn ($g) => (int) $g->max('page') < $map['investimento'])->count();

        $pct = $total ? (int) round($distintas / $total * 100) : 0;
        $chegouInvest = $ultima >= $map['investimento'];
        $chegouAceite = $ultima >= $map['aceite'];
        // Resumo HUMANO da leitura (vale mais que os indicadores soltos).
        if ($pct >= 90 && $chegouAceite) $resumo = 'Cliente leu praticamente toda a proposta e chegou até a seção de Aceite.';
        elseif ($chegouAceite) $resumo = 'Cliente chegou até a seção de Aceite.';
        elseif ($chegouInvest) $resumo = "Cliente leu até o Investimento ({$pct}%), mas não chegou ao Aceite.";
        elseif ($pct >= 50) $resumo = "Cliente leu parte da proposta ({$pct}%) e ainda não chegou ao Investimento.";
        else $resumo = "Cliente leu pouco da proposta ({$pct}%).";
        if ($ultima < $total && $ultima > 0) $resumo .= ' Parou em ' . $this->pageLabel($ultima, $map) . '.';

        // Tempo de PERMANÊNCIA real (soma das durações por página) — mais confiável que heartbeat.
        $permanencia = (int) $views->sum('duration_seconds');
        $visualizadores = $views->whereNotNull('crm_proposal_participant_id')->pluck('crm_proposal_participant_id')->unique()->count();

        return [
            'disponivel'            => true,
            'total_paginas'         => $total,
            'resumo_humano'         => $resumo,
            'leu_ate_fim'           => $ultima >= $total,
            'tempo_permanencia_seg' => $permanencia,
            'visualizadores_identificados' => $visualizadores,
            'leitura' => [
                'paginas_visualizadas' => $distintas,
                'percentual_lido'      => $pct,
                'ultima_pagina'        => $ultima,
                'ultima_pagina_label'  => $this->pageLabel($ultima, $map),
            ],
            'interesse' => [
                'pagina_mais_vista'  => $maisInteresse ? ['label' => $maisInteresse['label'], 'segundos' => $maisInteresse['segundos']] : null,
                'mensagem'           => $interesseMsg,
                'pagina_mais_vista_qualquer' => $mais ? ['label' => $mais['label'], 'segundos' => $mais['segundos']] : null,
                'pagina_menos_vista' => $menos ? ['label' => $menos['label'], 'segundos' => $menos['segundos']] : null,
                'tempo_medio_pagina_seg' => $tempoMedio,
                'por_pagina'         => $porPagina->sortBy('page')->values()->all(),
            ],
            'abandono' => [
                'pagina_abandono'      => $ultima < $total ? 'Após ' . $this->pageLabel($ultima, $map) : null,
                'ponto_medio_abandono' => $pontoMedio,
                'participantes_nao_investimento' => $naoInvest,
            ],
            'diagnostico' => [
                'chegou_investimento' => $ultima >= $map['investimento'],
                'chegou_aceite'       => $ultima >= $map['aceite'],
                'abandonou_em'        => $ultima < $total ? 'Após ' . $this->pageLabel($ultima, $map) : null,
                'pagina_mais_atencao' => $mais['label'] ?? null,
            ],
        ];
    }

    private function engajamento($p, $shares, $views, $threads, $parts, ?Carbon $enviadaC): array
    {
        $primeira = $shares->whereNotNull('first_viewed_at')->min('first_viewed_at');
        $primeiraC = $primeira ? Carbon::parse($primeira) : null;

        // 1ª interação = primeiro section_view OU primeira mensagem/thread (ação do cliente).
        $candidatos = collect([
            $views->min('entered_at'),
            $threads->min('created_at'),
        ])->filter()->map(fn ($d) => Carbon::parse($d));
        $primeiraInteracao = $candidatos->min();

        $ultimoAcesso = collect([
            $shares->max('last_viewed_at'),
            $parts->max('last_access_at'),
            $views->max('exited_at') ?: $views->max('entered_at'),
        ])->filter()->map(fn ($d) => Carbon::parse($d))->max();

        return [
            'enviada_em'            => optional($enviadaC)->toIso8601String(),
            'aberta'                => $primeiraC !== null,
            'primeira_abertura_em'  => optional($primeiraC)->toIso8601String(),
            'tempo_ate_abertura_min' => ($primeiraC && $enviadaC) ? $enviadaC->diffInMinutes($primeiraC) : null,
            'primeira_interacao_em' => optional($primeiraInteracao)->toIso8601String(),
            'tempo_ate_interacao_min' => ($primeiraInteracao && $enviadaC) ? $enviadaC->diffInMinutes($primeiraInteracao) : null,
            'total_visualizacoes'   => (int) $shares->sum('view_count'),
            'retornos'              => max(0, (int) $shares->sum('view_count') - 1),
            'tempo_leitura_seg'     => (int) $shares->sum('read_seconds'),
            'participantes_total'   => $parts->count(),
            'participantes_engajados' => $parts->filter(fn ($x) => $x->viewed_at || $x->last_access_at)->count(),
            'ultimo_acesso_em'      => optional($ultimoAcesso)->toIso8601String(),
        ];
    }

    private function navegacao($views, $titulos): array
    {
        // Agrega por seção: tempo total, nº de visitas, 1ª entrada (p/ ordem real).
        $porSecao = $views->groupBy('section_key')->map(function ($g, $key) use ($titulos) {
            return [
                'section_key' => $key, 'section_title' => $titulos[$key] ?? $key,
                'segundos'    => (int) $g->sum('duration_seconds'),
                'visitas'     => $g->count(),
                'primeira_em' => optional($g->min('entered_at'))->toIso8601String(),
            ];
        })->values();

        $maisVistas = $porSecao->sortByDesc('segundos')->values()->all();
        $ordem = $porSecao->sortBy('primeira_em')->pluck('section_title')->values()->all();
        // Seções comentáveis nunca abertas pelo cliente.
        $vistas = $porSecao->pluck('section_key')->all();
        $ignoradas = collect(ProposalSectionService::COMMENTABLE)
            ->reject(fn ($k) => in_array($k, $vistas, true))
            ->map(fn ($k) => $titulos[$k] ?? $k)->values()->all();

        return [
            'por_secao'        => $maisVistas,
            'mais_visualizada' => $maisVistas[0]['section_title'] ?? null,
            'ignoradas'        => $ignoradas,
            'ordem_navegacao'  => $ordem,
        ];
    }

    private function revisoes($threads, $titulos): array
    {
        $porSecao = $threads->groupBy('section_key')->map(fn ($g, $k) => [
            'section_key' => $k, 'section_title' => $titulos[$k] ?? $k, 'total' => $g->count(),
            'pendentes' => $g->whereIn('status', ['aberta', 'respondida'])->count(),
        ])->sortByDesc('total')->values();

        $porVersao = $threads->groupBy('opened_revision_number')->map(fn ($g, $v) => [
            'versao' => (int) $v, 'total' => $g->count(),
        ])->sortBy('versao')->values()->all();

        $resolvidas = $threads->where('status', 'resolvida')->filter(fn ($t) => $t->resolved_at && $t->created_at);
        $tempos = $resolvidas->map(fn ($t) => $t->created_at->diffInMinutes($t->resolved_at));
        $tempoMedioH = $tempos->isNotEmpty() ? round($tempos->avg() / 60, 1) : null;

        return [
            'total'            => $threads->count(),
            'pendentes'        => $threads->whereIn('status', ['aberta', 'respondida'])->count(),
            'por_secao'        => $porSecao->all(),
            'por_versao'       => $porVersao,
            'tempo_medio_resolucao_horas' => $tempoMedioH,
            'secao_mais_duvidas' => $porSecao->first()['section_title'] ?? null,
        ];
    }

    private function aprovacao($p, $parts, ?Carbon $enviadaC): array
    {
        $approvers = $parts->filter(fn ($x) => $x->hasRole('approver'));
        $signers   = $parts->filter(fn ($x) => $x->hasRole('signer'));
        $aprovadaEm = ($approvers->isNotEmpty() && $approvers->every(fn ($x) => $x->approved_at))
            ? $approvers->max('approved_at') : null;
        $assinadaEm = $this->assinaturaOk($p, $signers)
            ? $signers->filter(fn ($x) => $x->signed_at)->max('signed_at') : null;
        $aprovadaC = $aprovadaEm ? Carbon::parse($aprovadaEm) : null;
        $assinadaC = $assinadaEm ? Carbon::parse($assinadaEm) : null;
        $fimNeg = $p->liberado_em ? Carbon::parse($p->liberado_em) : ($assinadaC ?: now());

        return [
            'aprovada_em'            => optional($aprovadaC)->toIso8601String(),
            'assinada_em'            => optional($assinadaC)->toIso8601String(),
            'tempo_ate_aprovacao_h'  => ($aprovadaC && $enviadaC) ? round($enviadaC->diffInMinutes($aprovadaC) / 60, 1) : null,
            'tempo_ate_assinatura_h' => ($assinadaC && $enviadaC) ? round($enviadaC->diffInMinutes($assinadaC) / 60, 1) : null,
            'tempo_negociacao_dias'  => $enviadaC ? (int) $enviadaC->diffInDays($fimNeg) : null,
        ];
    }

    /** As 4 perguntas-chave do critério de sucesso. */
    private function resumoAcionavel($p, $shares, $views, $threads, $parts, ?Carbon $enviadaC, $titulos): array
    {
        $primeira = $shares->whereNotNull('first_viewed_at')->min('first_viewed_at');
        $comentou = $threads->isNotEmpty() || $views->isNotEmpty();
        // 1) cliente analisou?
        $analisou = !$primeira ? 'nao_abriu' : ($comentou ? 'analisou' : 'apenas_abriu');

        // 2) o que trava?
        $pendThreads = $threads->whereIn('status', ['aberta', 'respondida']);
        $trava = null;
        if ($pendThreads->isNotEmpty()) {
            $secs = $pendThreads->pluck('section_key')->unique()->map(fn ($k) => $titulos[$k] ?? $k)->implode(', ');
            $trava = "Revisões pendentes em: {$secs}";
        } elseif (in_array($p->status, self::FASES_ANALISE, true)) {
            $pend = $parts->filter(fn ($x) => $x->hasRole('approver') && !$x->approved_at)->count();
            $trava = $pend ? "Aguardando aprovação de {$pend} participante(s)" : ($primeira ? 'Cliente analisando' : 'Cliente ainda não abriu');
        } elseif (in_array($p->status, ['aprovada', 'aguardando_assinatura'], true)) {
            $pend = $parts->filter(fn ($x) => $x->hasRole('signer') && !$x->signed_at)->count();
            $trava = "Aguardando assinatura de {$pend} participante(s)";
        } elseif ($p->status === 'assinada') {
            $trava = 'Aguardando liberação comercial (handoff p/ Serviços)';
        } elseif ($p->status === 'liberada') {
            $trava = 'Aguardando criação do contrato operacional (Serviços)';
        } elseif ($p->status === 'convertida') {
            $trava = null;
        }

        // 3) quem precisa agir?
        $agir = [];
        if (in_array($p->status, self::FASES_ANALISE, true)) {
            $agir = $parts->filter(fn ($x) => $x->hasRole('approver') && !$x->approved_at)->map(fn ($x) => ['nome' => $x->name, 'acao' => 'aprovar'])->values()->all();
        } elseif (in_array($p->status, ['aprovada', 'aguardando_assinatura'], true)) {
            $agir = $parts->filter(fn ($x) => $x->hasRole('signer') && !$x->signed_at)->map(fn ($x) => ['nome' => $x->name, 'acao' => 'assinar'])->values()->all();
        }

        // 4) há quanto tempo parada?
        $ultimaAtividade = collect([
            $shares->max('last_viewed_at'),
            $parts->max('last_access_at'),
            $threads->max('updated_at'),
            $views->max('entered_at'),
            $enviadaC,
        ])->filter()->map(fn ($d) => Carbon::parse($d))->max();
        $diasParada = $ultimaAtividade ? (int) $ultimaAtividade->diffInDays(now()) : null;

        return [
            'cliente_analisou' => $analisou,                 // nao_abriu | apenas_abriu | analisou
            'o_que_trava'      => $trava,
            'quem_precisa_agir' => $agir,
            'dias_parada'      => $diasParada,
            'ultima_atividade_em' => optional($ultimaAtividade)->toIso8601String(),
        ];
    }
}
