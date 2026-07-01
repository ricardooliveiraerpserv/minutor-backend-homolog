<?php

namespace App\Services;

use App\Mail\ProposalInviteMail;
use App\Models\CrmOpportunityEvent;
use App\Models\CrmProposal;
use App\Models\CrmProposalParticipant;
use App\Models\DocumentEvent;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * P-B — Participantes da Proposta. Suporta múltiplos aprovadores e assinantes.
 *
 * Regras oficiais:
 *  - aprovada: se houver ≥1 Approver, TODOS devem aprovar. Sem Approver → aprovação dispensada.
 *  - assinada: se houver ≥1 Signer, TODOS devem assinar. Sem Signer → assinatura dispensada.
 */
class ProposalParticipantService
{
    private const TIPO_LABELS = [
        'bh_fixo' => 'Banco de Horas Fixo', 'bh_mensal' => 'Banco de Horas Mensal',
        'on_demand' => 'Consultoria Sob Demanda', 'projeto_fechado' => 'Projeto Fechado', 'cloud' => 'Cloud Protheus',
    ];

    /**
     * Adiciona participante + dispara o convite por e-mail (real). Reaproveita um participante
     * inativo com o mesmo e-mail (reativa, não duplica). $invitedBy null = adicionado no Portal pelo cliente.
     */
    public function adicionar(CrmProposal $p, string $name, string $email, array $roles, ?int $invitedBy): CrmProposalParticipant
    {
        $roles = array_values(array_intersect($roles, CrmProposalParticipant::ROLES)) ?: ['viewer'];

        $part = CrmProposalParticipant::where('crm_proposal_id', $p->id)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
        if ($part) {
            // MERGE de papéis: o mesmo participante pode validar E assinar (P-E.2.2).
            $roles = array_values(array_unique(array_merge((array) $part->roles, $roles)));
            $part->update(['name' => $name, 'email' => $email, 'roles' => $roles, 'is_active' => true]);
        } else {
            $part = CrmProposalParticipant::create([
                'crm_proposal_id' => $p->id, 'name' => $name, 'email' => $email, 'roles' => $roles,
                'participant_token' => CrmProposalParticipant::novoToken(),
                'invited_by' => $invitedBy, 'invited_at' => now(), 'is_active' => true,
            ]);
        }
        $this->marco($p, "{$name} convidado(a) como " . $this->rotulos($roles), 'participante_convidado', ['participant_id' => $part->id, 'roles' => $roles]);
        $this->enviarConvite($p, $part);
        return $part;
    }

    /**
     * Pré-popula os participantes da proposta a partir do CADERNO DO CLIENTE (P-E.2.2) — sem disparar
     * convite (a proposta ainda está em elaboração). Não duplica quem já existe por e-mail. Retorna quantos criou.
     */
    public function aplicarCaderno(CrmProposal $p): int
    {
        if (!$p->customer_id) return 0;
        $n = 0;
        foreach (\App\Models\CrmCustomerSigner::where('customer_id', $p->customer_id)->where('is_active', true)->get() as $s) {
            $existe = CrmProposalParticipant::where('crm_proposal_id', $p->id)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($s->email)])->exists();
            if ($existe) continue;
            $roles = array_values(array_intersect((array) $s->roles, CrmProposalParticipant::ROLES)) ?: ['viewer'];
            CrmProposalParticipant::create([
                'crm_proposal_id' => $p->id, 'name' => $s->name, 'email' => $s->email, 'roles' => $roles,
                'cargo' => $s->cargo, 'parte' => $s->parte,
                'participant_token' => CrmProposalParticipant::novoToken(), 'is_active' => true,
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * Garante o participante PRINCIPAL ao enviar a proposta (contato principal, papéis Approver+Signer).
     * Não dispara e-mail separado: o próprio e-mail da proposta JÁ leva o link individual (`?pt`) deste participante.
     * Reusa por e-mail (não duplica) e marca o convite (invited_at/last_invite_at/invite_count).
     */
    public function garantirPrincipal(CrmProposal $p, string $name, string $email, ?int $invitedBy): ?CrmProposalParticipant
    {
        if (!filled($email)) return null;
        $name = trim($name) ?: $email;

        $part = CrmProposalParticipant::where('crm_proposal_id', $p->id)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
        if ($part) {
            $roles = array_values(array_unique(array_merge((array) $part->roles, ['approver', 'signer'])));
            $part->update([
                'is_active' => true, 'roles' => $roles,
                'invited_at' => $part->invited_at ?: now(), 'last_invite_at' => now(),
                'invite_count' => (int) $part->invite_count + 1,
            ]);
        } else {
            $part = CrmProposalParticipant::create([
                'crm_proposal_id' => $p->id, 'name' => $name, 'email' => $email,
                'roles' => ['approver', 'signer'], 'participant_token' => CrmProposalParticipant::novoToken(),
                'invited_by' => $invitedBy, 'invited_at' => now(), 'last_invite_at' => now(),
                'invite_count' => 1, 'is_active' => true,
            ]);
            $this->marco($p, "{$name} definido(a) como participante principal (Approver + Signer)", 'participante_principal', [
                'participant_id' => $part->id, 'email' => $email,
            ]);
        }
        return $part;
    }

    /** Reenvia o convite (não cria participante novo). Atualiza contagem/última data + evento. */
    public function reenviar(CrmProposalParticipant $part, ?int $by = null): bool
    {
        $p = $part->proposal;
        if (!$part->is_active) {
            throw new \RuntimeException('Participante inativo — reative antes de reenviar.');
        }
        $sent = $this->enviarConvite($p, $part, true);
        $this->marco($p, "Convite reenviado para {$part->name} ({$part->email})", 'participante_reconvidado', [
            'participant_id' => $part->id, 'by' => $by, 'invite_count' => $part->fresh()->invite_count,
        ]);
        return $sent;
    }

    /** Desativa o participante (não exclui histórico). Desativados não recebem e-mail nem acessam o Portal. */
    public function desativar(CrmProposalParticipant $part, ?int $by = null): void
    {
        if (!$part->is_active) return;
        $part->update(['is_active' => false]);
        $this->marco($part->proposal, "{$part->name} ({$part->email}) desativado(a) da proposta", 'participante_desativado', [
            'participant_id' => $part->id, 'by' => $by,
        ]);
    }

    /**
     * Disparo REAL do convite por e-mail. Cada participante recebe seu link exclusivo (`?pt=`).
     * Registra invited_at/last_invite_at/invite_count independentemente do sucesso do envio (best-effort).
     */
    public function enviarConvite(CrmProposal $p, CrmProposalParticipant $part, bool $reenvio = false): bool
    {
        $part->update([
            'invited_at'   => $part->invited_at ?: now(),
            'last_invite_at' => now(),
            'invite_count' => (int) $part->invite_count + 1,
        ]);

        if (!$part->is_active || !filled($part->email)) return false;

        $link = $this->linkParticipante($p, $part);
        if (!$link) {
            \Log::warning('[participante] convite sem share ativo (sem link)', ['part' => $part->id, 'proposta' => $p->codigo]);
            return false;
        }

        $fromUser    = $part->invited_by ? User::find($part->invited_by) : null;
        $senderName  = $fromUser?->name ?: (string) config('mail.from.name', 'ERPSERV Consultoria');
        $clienteName = (string) ($p->customer?->name ?? 'sua empresa');
        $tipo        = $p->tipo ?: 'bh_fixo';
        $subject     = ($reenvio ? 'Lembrete — ' : '') . 'Proposta Comercial' . ($p->codigo ? ' ' . $p->codigo : '') . ' — ' . $clienteName;

        $mc = $fromUser
            ? \App\Services\SenderMailer::for(
                $fromUser,
                (string) config('mail.fechamento_cliente_mailer', 'nfe'),
                (string) config('mail.fechamento_cliente_from', config('mail.from.address')),
                config('mail.fechamento_cliente_from_name', config('mail.from.name', 'ERPSERV Consultoria')),
            )
            : [
                'mailer' => (string) config('mail.fechamento_cliente_mailer', 'nfe'),
                'from_address' => (string) config('mail.fechamento_cliente_from', config('mail.from.address')),
                'from_name' => config('mail.fechamento_cliente_from_name', config('mail.from.name', 'ERPSERV Consultoria')),
            ];

        $mailable = new ProposalInviteMail(
            participantName: $part->name,
            papeisLabel:     $this->rotulos((array) $part->roles),
            clienteName:     $clienteName,
            senderName:      $senderName,
            subjectLine:     $subject,
            portalUrl:       $link,
            codigo:          $p->codigo,
            tipoLabel:       self::TIPO_LABELS[$tipo] ?? null,
            valorTotal:      'R$ ' . number_format((float) $p->total, 2, ',', '.'),
            validade:        optional($p->data_validade)->format('d/m/Y'),
            senderEmail:     $fromUser?->email,
            isReenvio:       $reenvio,
            fromAddress:     $mc['from_address'],
            fromName:        $mc['from_name'],
        );

        try {
            if (\App\Services\GraphMailer::enabled() && $fromUser && filled($fromUser->email)) {
                \App\Services\GraphMailer::sendAs($fromUser->email, [$part->email], [], $subject, $mailable->render(), []);
            } else {
                Mail::mailer($mc['mailer'])->to($part->email)->send($mailable);
            }
            return true;
        } catch (\Throwable $e) {
            \Log::error('[participante] falha ao enviar convite', ['part' => $part->id, 'proposta' => $p->codigo, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    /** Link individual do participante: `/p/{share}?pt={participant_token}` (share ativo da proposta). */
    private function linkParticipante(CrmProposal $p, CrmProposalParticipant $part): ?string
    {
        $share = $p->shares()->whereNull('revoked_at')->orderByDesc('id')->first();
        if (!$share) return null;
        $base = rtrim((string) (env('FRONTEND_URL') ?: config('app.frontend_url') ?: config('app.url') ?: 'https://app.minutor.com.br'), '/');
        return $base . '/p/' . $share->token . '?pt=' . $part->participant_token;
    }

    /** Registra acesso do participante: aceite do convite (1ª vez, com ip/ua) + visualização + contagem. */
    public function registrarAcesso(CrmProposalParticipant $part, ?string $ip = null, ?string $ua = null): void
    {
        $primeiroAceite = !$part->accepted_at;
        $patch = ['last_access_at' => now(), 'access_count' => (int) $part->access_count + 1];
        if (!$part->viewed_at) $patch['viewed_at'] = now();
        if ($primeiroAceite) {
            $patch['accepted_at'] = now();
            $patch['accepted_ip'] = $ip ? substr($ip, 0, 64) : null;
            $patch['accepted_user_agent'] = $ua ? substr($ua, 0, 1000) : null;
        }
        $part->update($patch);
        if ($primeiroAceite) {
            $this->marco($part->proposal, "{$part->name} aceitou o convite e acessou a proposta", 'participante_aceitou_convite', [
                'participant_id' => $part->id, 'ip' => $ip, 'ua' => substr((string) $ua, 0, 240),
            ]);
        }
    }

    /** Aprovação comercial por participante (Approver). */
    public function aprovar(CrmProposalParticipant $part, ?string $ip, ?string $ua, ?string $comentario = null): void
    {
        if (!$part->hasRole('approver')) {
            throw new \RuntimeException('Este participante não tem papel de Aprovador.');
        }
        if ($part->approved_at) return; // idempotente
        $part->update([
            'approved_at' => now(),
            'approval_comment' => $comentario ? mb_substr($comentario, 0, 1000) : null,
            'approval_ip' => $ip ? substr($ip, 0, 64) : null,
            'approval_user_agent' => $ua ? substr($ua, 0, 1000) : null,
        ]);
        $p = $part->proposal;
        $this->marco($p, "{$part->name} APROVOU a proposta" . ($comentario ? " — \"{$comentario}\"" : ''), 'proposta_aprovada', ['participant_id' => $part->id, 'ip' => $ip, 'ua' => substr((string) $ua, 0, 240)]);
        $this->recomputarAprovacao($p->fresh(['participants']));
    }

    /** Assinatura digital por participante (Signer) — captura nome/CPF/cargo + traço + evidências. */
    public function assinar(CrmProposalParticipant $part, ?string $ip, ?string $ua, array $dados = []): void
    {
        if (!$part->hasRole('signer')) {
            throw new \RuntimeException('Este participante não tem papel de Assinante.');
        }
        $p = $part->proposal->loadMissing('document');
        if (!in_array($p->status, ['aprovada', 'aguardando_assinatura'], true)) {
            throw new \RuntimeException('A proposta precisa estar aprovada antes da assinatura.');
        }
        if ($part->signed_at) return;
        // P-E.2.4 — reuso da assinatura salva: campos vazios herdam o perfil do e-mail (sem redigitar/desenhar).
        $perfil = \App\Models\CrmSignatureProfile::porEmail($part->email);
        $nome  = $dados['nome']  ?? null ?: ($perfil?->name ?: $part->name);
        $cpf   = $dados['cpf']   ?? null ?: $perfil?->cpf;
        $cargo = $dados['cargo'] ?? null ?: ($perfil?->cargo ?: $part->cargo);
        $imagem = (isset($dados['imagem']) && str_starts_with((string) $dados['imagem'], 'data:image/')) ? $dados['imagem'] : $perfil?->image;
        $part->update([
            'signed_at' => now(),
            'sign_name' => $nome,
            'sign_cpf' => $cpf,
            'sign_cargo' => $cargo,
            'sign_image' => $imagem,
            'sign_ip' => $ip ? substr($ip, 0, 64) : null,
            'sign_user_agent' => $ua ? substr($ua, 0, 1000) : null,
            'sign_doc_hash' => $p->document?->hash,
            'sign_doc_version' => $p->versao,
        ]);
        \App\Models\CrmSignatureProfile::lembrar($part->email, $nome, $cpf, $cargo, $imagem);
        $p->document?->logEvent(DocumentEvent::TYPE_ASSINATURA_INICIADA, ['participant_id' => $part->id, 'ip' => $ip], null);
        $this->marco($p, "{$part->name} ASSINOU a proposta", 'assinatura_concluida', ['participant_id' => $part->id, 'ip' => $ip, 'ua' => substr((string) $ua, 0, 240)]);
        $this->recomputarAssinatura($p->fresh(['participants']));
    }

    /** aprovada quando TODOS os Approvers aprovaram. */
    public function recomputarAprovacao(CrmProposal $p): bool
    {
        $approvers = $p->participants->where('is_active', true)->filter(fn ($x) => $x->hasRole('approver'));
        if ($approvers->isEmpty()) return false; // sem approver → dispensado (não auto-aprova)
        if ($approvers->contains(fn ($x) => $x->approved_at === null)) return false;
        if (in_array($p->status, ['enviada', 'em_analise', 'em_negociacao', 'em_revisao'], true)) {
            $p->update(['status' => 'aprovada']);
            $this->marco($p, "Proposta {$p->codigo} APROVADA (todos os aprovadores) — {$approvers->count()} aprovação(ões)", 'aprovacao_concluida', []);
        }
        return true;
    }

    /**
     * Critério de conclusão da assinatura (P-E.2.2), parametrizável por `assinatura_modo`:
     *  - 'todos' (padrão): todos os signatários ativos assinaram.
     *  - 'um_por_parte': ao menos um signatário por parte que tenha signatários (contratada e contratante).
     */
    public function assinaturaCompleta(CrmProposal $p): bool
    {
        $signers = $p->participants->where('is_active', true)->filter(fn ($x) => $x->hasRole('signer'));
        if ($signers->isEmpty()) return false;

        if (($p->assinatura_modo ?? 'todos') === 'um_por_parte') {
            foreach ($signers->groupBy(fn ($x) => $x->parte ?: 'indefinida') as $grupo) {
                if (!$grupo->contains(fn ($x) => $x->signed_at !== null)) return false; // parte sem nenhuma assinatura
            }
            return true;
        }

        return !$signers->contains(fn ($x) => $x->signed_at === null);
    }

    /** Marca a proposta como assinada quando o critério de conclusão (por modo) é atendido. */
    public function recomputarAssinatura(CrmProposal $p): bool
    {
        $signers = $p->participants->where('is_active', true)->filter(fn ($x) => $x->hasRole('signer'));
        if ($signers->isEmpty()) return false;
        // Auto-cura: se está 'assinada' mas o critério NÃO é mais atendido (ex.: novo assinante adicionado),
        // rebaixa p/ 'aguardando_assinatura' para liberar a assinatura pendente.
        if ($p->status === 'assinada' && !$this->assinaturaCompleta($p)) {
            $p->update(['status' => 'aguardando_assinatura']);
            return false;
        }
        if (!$this->assinaturaCompleta($p)) return false;
        if (in_array($p->status, ['aprovada', 'aguardando_assinatura'], true)) {
            $p->update(['status' => 'assinada']);
            $p->document?->update(['status' => 'aprovada']);
            $p->document?->logEvent(DocumentEvent::TYPE_ASSINATURA_CONCLUIDA, ['signers' => $signers->count()], null);
            $this->marco($p, "Proposta {$p->codigo} ASSINADA (todos os {$signers->count()} signatários)", 'assinatura_finalizada', []);
            // P-E.2.4 — regenera o PDF p/ estampar o REGISTRO DE ASSINATURAS no corpo da proposta.
            try {
                $actor = $p->vendedor ?: ($p->created_by_id ? \App\Models\User::find($p->created_by_id) : null) ?: \App\Models\User::where('type', 'admin')->first();
                if ($actor) app(\App\Documents\CrmProposalService::class)->gerarDocumento($p->fresh(), $actor, true);
            } catch (\Throwable $e) { \Log::warning('[assinatura] falha ao regenerar PDF com registro: ' . $e->getMessage()); }
        }
        return true;
    }

    /** P-E.2.4 — marca o participante como assinado via Clicksign (usado pelo sync sem webhook). */
    public function marcarAssinouViaClicksign(CrmProposalParticipant $part, $signer, CrmProposal $p): void
    {
        if ($part->signed_at) return;
        $doc = $p->loadMissing('document')->document;
        $part->update([
            'signed_at' => now(), 'sign_status' => 'signed', 'sign_status_at' => now(),
            'sign_name' => $signer->name ?: $part->name, 'sign_doc_hash' => $doc?->hash,
            'sign_doc_version' => $p->versao, 'sign_user_agent' => 'Clicksign',
        ]);
        $this->marco($p, "{$part->name} ASSINOU a proposta (Clicksign)", 'assinatura_concluida', ['participant_id' => $part->id]);
        $this->recomputarAssinatura($p->fresh(['participants']));
    }

    private function rotulos(array $roles): string
    {
        return implode(' + ', array_map(fn ($r) => CrmProposalParticipant::ROLE_LABELS[$r] ?? $r, $roles));
    }

    private function marco(CrmProposal $p, string $label, string $kind, array $meta): void
    {
        if (!$p->opportunity_id) return;
        CrmOpportunityEvent::log((int) $p->opportunity_id, 'note', [
            'to_value' => $label, 'triggered_by' => null, 'meta' => array_merge(['kind' => $kind], $meta),
        ]);
    }
}
