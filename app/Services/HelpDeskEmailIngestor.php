<?php

namespace App\Services;

use App\Attachments\AttachmentService;
use App\Attachments\Storage\StorageProvider;
use App\Models\CustomerContact;
use App\Models\HelpDeskAssociationRule;
use App\Models\HelpDeskEmailAccount;
use App\Models\HelpDeskIngestedEmail;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ingestão de e-mails → chamados do Help Desk.
 *
 * Lê a inbox via Microsoft Graph (app-only, Mail.Read), aplica as regras da conta
 * (accept_from + Regras de Associação) e abre um chamado novo ou anexa a resposta a
 * um chamado existente — reusando o fluxo oficial (status default, SLA, evento).
 *
 * Dedup: ledger helpdesk_ingested_emails (message_id único por conta). Como só temos
 * Mail.Read, não dá pra marcar lido/mover no Graph — o ledger é a fonte da verdade.
 *
 * Hoje só o provedor microsoft365 (Graph) é suportado; contas IMAP são puladas
 * (a busca IMAP real ainda não foi implementada).
 */
class HelpDeskEmailIngestor
{
    private ?User $actor = null;

    /** Ação gravada no ledger para o e-mail em processamento (usada p/ "marcar como lido"). */
    private ?string $lastLedgerAction = null;

    public function __construct(
        private HelpDeskSlaService $sla,
        private AttachmentService $attachments,
        private StorageProvider $storage,
    ) {
    }

    /**
     * @return array{accounts:int, fetched:int, tickets:int, comments:int, ignored:int, errors:int, details:array<int,string>}
     */
    public function run(?int $accountId = null, int $perAccount = 25): array
    {
        $sum = ['accounts' => 0, 'fetched' => 0, 'tickets' => 0, 'comments' => 0, 'ignored' => 0, 'errors' => 0, 'details' => []];

        $accounts = HelpDeskEmailAccount::query()
            ->where('enabled', true)->where('receive_enabled', true)
            ->when($accountId, fn ($q) => $q->where('id', $accountId))
            ->get();

        foreach ($accounts as $acc) {
            if ($acc->provider !== 'microsoft365') {
                $sum['details'][] = "Conta #{$acc->id} ({$acc->email}): provedor '{$acc->provider}' ainda não suportado na ingestão (só microsoft365).";
                continue;
            }
            $sum['accounts']++;

            // Ingestão roda em job background (cron), SEM empresa corrente. Com o multi-empresa
            // ligado (homolog), o trait BelongsToCompany carimba company_id e o CompanyScope
            // escopa numeração/queries pela empresa ATIVA — sem contexto, o ticket nasce órfão
            // (invisível na UI), casa triggers de forma errada e o número do chamado colide.
            // Fixa a empresa dona da caixa durante o processamento desta conta (inerte em prod,
            // onde multiempresa.scoping_enabled = false).
            $companyCtx = app(\App\Services\CompanyContext::class);
            $companyCtx->set($acc->company_id);
            try {
                $messages = GraphMailReader::recentMessages((string) $acc->email, $perAccount);
                // Mais antigas primeiro, p/ manter a ordem cronológica das respostas.
                $messages = array_reverse($messages);

                // Opção da conta "Marcar e-mail como lido ao importá-lo" (settings.mark_read).
                $markRead = !empty(((array) ($acc->settings ?? []))['mark_read']);

                foreach ($messages as $msg) {
                    $sum['fetched']++;
                    try {
                        $this->lastLedgerAction = null;
                        $this->processOne($acc, $msg, $sum);
                        // Só marca o que foi de fato IMPORTADO (virou chamado ou resposta); e-mail
                        // ignorado (bounce, anti-loop, remetente barrado) continua como estava.
                        // Interruptor global (env HD_MARK_READ_ENABLED, padrão DESLIGADO): só liga após decisão do Ricardo
                        // e da permissão Mail.ReadWrite no Azure — muda o estado da caixa de suporte.
                        if ($markRead && env('HD_MARK_READ_ENABLED', false) && in_array($this->lastLedgerAction, ['ticket_created', 'comment_appended'], true)
                            && empty($msg['isRead'])) {
                            GraphMailReader::markAsRead((string) $acc->email, (string) ($msg['id'] ?? ''));
                        }
                    } catch (\Throwable $e) {
                        $sum['errors']++;
                        $sum['details'][] = "Conta #{$acc->id}: erro em '" . ($msg['id'] ?? '?') . "': " . $e->getMessage();
                    }
                }
            } finally {
                $companyCtx->forget();
            }
        }

        return $sum;
    }

    /** @param array<string,mixed> $msg */
    private function processOne(HelpDeskEmailAccount $acc, array $msg, array &$sum): void
    {
        $messageId = (string) ($msg['id'] ?? '');
        if ($messageId === '') return;

        // Dedup: já processado? Checagem GLOBAL (bypass do CompanyScope): o graph_message_id é
        // único por caixa, independe de empresa. Como agora processamos sob o CompanyContext da
        // conta, escopar o dedup por empresa faria linhas de ledger sem company_id (legado) ou de
        // outra empresa escaparem da checagem e o e-mail ser reprocessado em loop a cada rodada.
        if (HelpDeskIngestedEmail::withoutGlobalScopes()->where('email_account_id', $acc->id)->where('graph_message_id', $messageId)->exists()) {
            return;
        }

        $fromEmail = strtolower(trim((string) data_get($msg, 'from.emailAddress.address', '')));
        $fromName  = trim((string) data_get($msg, 'from.emailAddress.name', '')) ?: $fromEmail;
        $subject   = trim((string) ($msg['subject'] ?? '')) ?: '(sem assunto)';
        $body      = (string) (data_get($msg, 'body.content') ?: data_get($msg, 'bodyPreview', ''));
        // NÃO corta o histórico aqui: ao ABRIR chamado (inclusive e-mail ENCAMINHADO), toda a
        // thread encaminhada deve ir INTEIRA para a 1ª interação (description). O corte do
        // histórico citado é aplicado APENAS em RESPOSTA a chamado existente (appendClientReply).
        $receivedAt = ($r = data_get($msg, 'receivedDateTime')) ? Carbon::parse($r) : now();

        // Backup de dedup: se um run ANTERIOR já ABRIU um chamado com ESTE e-mail (external_ref),
        // mas falhou ao gravar o ledger (ex.: timeout no storeFiles/trigger com corpo gigante),
        // não reprocessar — senão o mesmo e-mail viraria um comentário duplicado. O external_ref
        // é gravado na MESMA transação do ticket, então é garantido quando o chamado existe.
        $extRef = 'gm:' . substr(sha1($messageId), 0, 40);
        if (HelpDeskTicket::withoutGlobalScopes()->where('external_ref', $extRef)->exists()) {
            $this->ledger($acc, $messageId, $fromEmail, $subject, 'ignored', 'ticket_ja_aberto_deste_email', $receivedAt, $sum);
            return;
        }

        // Anti-loop: ignora e-mail enviado pela própria caixa (cópia/auto-resposta).
        if ($fromEmail !== '' && strcasecmp($fromEmail, (string) $acc->email) === 0) {
            $this->ledger($acc, $messageId, $fromEmail, $subject, 'ignored', 'auto_da_propria_caixa', $receivedAt, $sum);
            return;
        }

        // Bounces/NDR (MicrosoftExchange/postmaster/mailer-daemon) e auto-respostas NÃO viram chamado.
        if ($this->looksAutoGenerated($fromEmail, $subject)) {
            $this->ledger($acc, $messageId, $fromEmail, $subject, 'ignored', 'bounce_ou_auto_resposta', $receivedAt, $sum);
            return;
        }

        $settings = (array) ($acc->settings ?? []);

        // Janela "importar somente a partir de".
        if (!empty($settings['import_from'])) {
            try {
                if ($receivedAt->lt(Carbon::parse($settings['import_from'])->startOfDay())) {
                    $this->ledger($acc, $messageId, $fromEmail, $subject, 'ignored', 'antes_de_import_from', $receivedAt, $sum);
                    return;
                }
            } catch (\Throwable) { /* import_from inválido → ignora a janela */ }
        }

        // Resolve cliente/contato pelo remetente (contato cadastrado tem prioridade; senão, regra de associação por domínio).
        $contact = $fromEmail ? CustomerContact::whereRaw('lower(email) = ?', [$fromEmail])->first() : null;
        $rule    = $fromEmail ? HelpDeskAssociationRule::matchEmail($fromEmail) : null;
        $customerId = $contact?->customer_id ?? $rule?->customer_id;

        // Solicitante automático: se a empresa foi identificada (por regra) mas o remetente
        // ainda não é contato, cadastra-o sob essa empresa — assim vira contato reutilizável.
        if (!$contact && $customerId && $fromEmail) {
            $contact = CustomerContact::create(['customer_id' => $customerId, 'name' => $fromName, 'email' => $fromEmail]);
        }

        // Pré-cadastro de USUÁRIO cliente (paridade Movidesk): remetente identificado por
        // empresa vira um usuário type=cliente com acesso SÓ ao Help Desk, SEM senha — não
        // loga até ser convidado (fase 1b). Dedup por e-mail; nunca quebra a ingestão.
        if ($customerId && $fromEmail) {
            $this->preRegisterClientUser($fromEmail, $fromName, (int) $customerId, $rule);
        }

        // Política de abertura (accept_from).
        $acceptFrom = $settings['accept_from'] ?? 'any';
        $allowed = match ($acceptFrom) {
            'none'             => false,
            'customer'         => (bool) $contact,
            'customer_or_rule' => (bool) ($contact || $rule),
            default            => true, // 'any'
        };
        if (!$allowed) {
            $this->ledger($acc, $messageId, $fromEmail, $subject, 'ignored', 'accept_from:' . $acceptFrom, $receivedAt, $sum);
            return;
        }

        // Anexos: embute as imagens inline (cid) da assinatura/corpo direto no HTML como data:
        // (preserva a assinatura original ao renderizar); guarda só os anexos REAIS (não-inline).
        // Inline-only às vezes vem com hasAttachments=false no Graph → busca tb se há cid: no corpo.
        $inboundFiles = [];
        if (!empty($msg['hasAttachments']) || str_contains($body, 'cid:')) {
            $graphAtts = GraphMailReader::messageAttachments((string) $acc->email, $messageId);
            [$body, $inboundFiles] = $this->embedInlineImages($body, $graphAtts);
        }

        // Resposta a chamado existente? Threading por token [HD-xxxxxx] tem prioridade
        // (resposta a uma resposta nossa); senão, por assunto normalizado.
        $appendExisting     = $settings['append_existing'] ?? true;
        $associateExisting  = $settings['associate_existing'] ?? 'customer';
        $existing = $this->matchByTicketToken($subject, $acc->company_id ? (int) $acc->company_id : null, $fromEmail ?: null, $customerId ? (int) $customerId : null);
        if (!$existing && $appendExisting && $associateExisting !== 'new') {
            // Modo 'customer': só casa por assunto DENTRO do mesmo cliente. Se o remetente NÃO foi
            // associado a um cliente (sem contato/regra), NÃO anexa por assunto — senão um e-mail de
            // remetente desconhecido cairia num chamado de QUALQUER cliente com o mesmo assunto
            // (ex.: "TESTE"). Nesse caso cria chamado NOVO.
            if ($associateExisting === 'customer' && !$customerId) {
                $existing = null;
            } else {
                $existing = $this->findOpenTicketBySubject($subject, $associateExisting === 'customer' ? $customerId : null);
            }
        }
        if ($existing) {
            $existing->loadMissing('status');
            // Alvo onde a resposta será anexada. Regra:
            //  - chamado ABERTO/RESOLVIDO → anexa nele mesmo (e move p/ Em andamento);
            //  - chamado ENCERRADO com continuação ABERTA → anexa NA continuação (interação, não cria outro);
            //  - chamado ENCERRADO sem continuação aberta → cria a 1ª continuação + avisa o cliente.
            $target = $existing;
            $detail = "↩︎ Resposta anexada ao {$existing->ticket_number} → Em atendimento (de {$fromEmail}).";
            $ledgerReason = null;

            if (optional($existing->status)->is_terminal) {
                // Continuação ABERTA já existente deste chamado (respostas seguintes vão pra ela).
                $active = HelpDeskTicket::where('previous_ticket_id', $existing->id)
                    ->whereHas('status', fn ($q) => $q->where('is_terminal', false))
                    ->orderByDesc('id')->first();
                if (!$active) {
                    // 1ª resposta a chamado encerrado → abre a continuação e avisa o cliente.
                    $new = $this->openContinuationTicket($acc, $existing, $subject, $body, $customerId, $contact, $messageId, $receivedAt, $fromEmail, $fromName);
                    $sum['tickets']++;
                    $this->mergeInvolvedEmails($new, $this->involvedEmails($msg, (string) $acc->email, $fromEmail));
                    $this->storeFiles('HELPDESK_TICKET', $new->id, $inboundFiles);
                    try {
                        HelpDeskReplyMailer::sendClosedTicketNotice($acc, $existing, $new, $fromEmail);
                    } catch (\Throwable $e) {
                        Log::warning('HelpDesk: aviso de chamado encerrado lançou: ' . $e->getMessage());
                    }
                    HelpDeskTriggerEngine::dispatch('ticket_created', $new->fresh(), ['comment_by' => 'client', 'actor_email' => $fromEmail]);
                    $sum['details'][] = "🔁 {$existing->ticket_number} encerrado → novo {$new->ticket_number} de {$fromEmail}.";
                    $this->ledger($acc, $messageId, $fromEmail, $subject, 'ticket_created', 'continua_' . $existing->ticket_number, $receivedAt, $sum, $new->id);
                    return;
                }
                // Continuação já existe e está aberta → esta resposta é interação NELA.
                $target = $active;
                $detail = "↩︎ Resposta ao {$existing->ticket_number} (encerrado) → interação na continuação {$active->ticket_number} (de {$fromEmail}).";
                $ledgerReason = 'continuacao_' . $existing->ticket_number;
            }

            // Anexa a resposta no alvo e move para "Em andamento" (reativa o atendimento).
            $comment = $this->appendClientReply($target, $body, $contact, $messageId, $receivedAt);
            // Captura novos envolvidos (Para + CC) que apareçam nesta resposta → recebem as próximas respostas.
            $this->mergeInvolvedEmails($target, $this->involvedEmails($msg, (string) $acc->email, $fromEmail));
            $sum['comments']++;
            $this->storeFiles('HELPDESK_TICKET_COMMENT', $comment->id, $inboundFiles);
            HelpDeskTriggerEngine::dispatch('comment_added', $target->fresh(), ['comment_by' => 'client', 'actor_email' => $fromEmail]);
            $sum['details'][] = $detail;
            $this->ledger($acc, $messageId, $fromEmail, $subject, 'comment_appended', $ledgerReason, $receivedAt, $sum, $target->id, $comment->id);
            return;
        }

        // Abre chamado novo — mesmo fluxo do store() oficial.
        $ticket = DB::transaction(function () use ($acc, $subject, $body, $customerId, $contact, $messageId, $receivedAt, $fromEmail, $fromName) {
            $t = HelpDeskTicket::create([
                'ticket_number'       => HelpDeskTicketNumber::next(), // mesma numeração configurada do portal
                'subject'             => mb_substr($subject, 0, 195),
                'description'         => $body,
                'customer_id'         => $customerId,
                'customer_contact_id' => $contact?->id,
                'requester_name'      => $contact?->name ?: $fromName,
                'requester_email'     => $fromEmail ?: null,
                'priority'            => 'normal',
                'channel'             => 'email',
                'status_id'           => optional(HelpDeskStatus::default())->id,
                'team_id'             => $acc->default_team_id,
                // Âncora do threading: guardamos o id Graph da msg do cliente p/ responder na MESMA
                // conversa (createReply) — assim confirmação e respostas caem num e-mail único na caixa dele.
                'graph_thread_msg_id' => $messageId,
                'source_system'       => 'email:graph',
                // id do Graph é longo (>120); guardamos um hash curto aqui e o id completo no ledger.
                'external_ref'        => 'gm:' . substr(sha1($messageId), 0, 40),
                'last_activity_at'    => $receivedAt,
            ]);
            $this->sla->apply($t);
            HelpDeskTicketEvent::log($t->id, 'created', ['to_value' => $t->subject, 'meta' => ['via' => 'email', 'from' => $fromEmail]]);
            return $t;
        });
        $sum['tickets']++;
        // Grava o ledger (marca de dedup) IMEDIATAMENTE após criar o chamado — ANTES de
        // storeFiles/trigger, que podem falhar/estourar (corpo gigante) e deixariam o e-mail
        // sem marca → reprocessado e duplicado na rodada seguinte.
        $this->ledger($acc, $messageId, $fromEmail, $subject, 'ticket_created', null, $receivedAt, $sum, $ticket->id);
        // Envolvidos (Para + CC) do e-mail de abertura → viram cc_emails do chamado (recebem as respostas).
        $this->mergeInvolvedEmails($ticket, $this->involvedEmails($msg, (string) $acc->email, $fromEmail));
        $this->storeFiles('HELPDESK_TICKET', $ticket->id, $inboundFiles);
        // Sem actor_email: num ticket aberto por e-mail o "cliente" É quem disparou; com
        // actor_email o skip_actor do trigger de confirmação (#1) zerava os destinatários e
        // o cliente nunca recebia o e-mail com o nº do chamado. (comment_added mantém actor_email.)
        HelpDeskTriggerEngine::dispatch('ticket_created', $ticket->fresh(), ['comment_by' => 'client']);
        $sum['details'][] = "✅ Chamado {$ticket->ticket_number} aberto de {$fromEmail}: \"{$subject}\".";
    }

    /** Endereços ENVOLVIDOS (Para + CC) do e-mail recebido — fora o remetente e a própria caixa do
     *  Help Desk. Normalizados (minúsculas), válidos e únicos. São as pessoas "em cópia" a preservar. */
    private function involvedEmails(array $msg, string $accountEmail, string $fromEmail): array
    {
        $acc = strtolower(trim($accountEmail));
        $from = strtolower(trim($fromEmail));
        $out = [];
        foreach (['toRecipients', 'ccRecipients'] as $k) {
            foreach ((array) ($msg[$k] ?? []) as $r) {
                $e = strtolower(trim((string) data_get($r, 'emailAddress.address', '')));
                if ($e === '' || $e === $acc || $e === $from) continue;
                if (!filter_var($e, FILTER_VALIDATE_EMAIL)) continue;
                $out[$e] = $e;
            }
        }
        return array_values($out);
    }

    /** Mescla os envolvidos (Para+CC) no cc_emails do chamado — sem duplicar e sem incluir o
     *  solicitante (requester_email). Preserva o que já existia. */
    private function mergeInvolvedEmails(HelpDeskTicket $ticket, array $involved): void
    {
        if (empty($involved)) return;
        $existing = array_values(array_unique(array_map('strtolower', array_filter((array) $ticket->cc_emails))));
        $req = strtolower((string) $ticket->requester_email);
        $merged = $existing;
        foreach ($involved as $e) {
            if ($e === $req) continue;
            if (!in_array($e, $merged, true)) $merged[] = $e;
        }
        if (count($merged) !== count($existing)) {
            $ticket->cc_emails = $merged;
            $ticket->save();
        }
    }

    /** Anexa a resposta do cliente (e-mail) como interação no chamado alvo e reativa (Em andamento). */
    private function appendClientReply(HelpDeskTicket $ticket, string $body, ?CustomerContact $contact, string $messageId, Carbon $receivedAt): \App\Models\HelpDeskTicketComment
    {
        // Resposta a chamado existente: corta o histórico citado, mantendo só o que o
        // cliente escreveu ACIMA do marcador do e-mail anterior (a abertura preserva tudo).
        $body = $this->stripQuotedHistory($body);
        return DB::transaction(function () use ($ticket, $body, $contact, $messageId, $receivedAt) {
            $c = $ticket->comments()->create([
                'author_contact_id' => $contact?->id,
                'body'              => $body,
                'visibility'        => 'customer',
                'channel'           => 'email',
                'idempotency_key'   => 'email:' . substr(sha1($messageId), 0, 40), // id do Graph não cabe em varchar(80)
            ]);
            // Atualiza a âncora p/ a última msg do cliente → futuras respostas threadam nela.
            $ticket->update(['last_activity_at' => $receivedAt, 'graph_thread_msg_id' => $messageId]);
            HelpDeskTicketEvent::log($ticket->id, 'comment', ['meta' => ['comment_id' => $c->id, 'visibility' => 'customer', 'via' => 'email']]);
            $this->moveToEmAndamento($ticket); // resposta do cliente reativa o chamado
            return $c;
        });
    }

    /** Detecta bounces/NDR e auto-respostas (não devem virar chamado nem resposta). */
    private function looksAutoGenerated(string $fromEmail, string $subject): bool
    {
        $from = strtolower(trim($fromEmail));
        if ($from !== '' && (
            str_contains($from, 'microsoftexchange') ||
            str_starts_with($from, 'postmaster@') ||
            str_starts_with($from, 'mailer-daemon') ||
            str_contains($from, 'no-reply@microsoft') ||
            str_starts_with($from, 'noreply@')
        )) {
            return true;
        }
        // Padrões de NDR (falha de entrega) e auto-resposta no assunto (PT/EN).
        $s = trim($subject);
        return (bool) preg_match(
            '/^\s*(n[ãa]o\s+é?\s*poss[íi]vel\s+entregar|undeliverable|delivery\s+status\s+notification|mail\s+delivery|returned\s+mail|failure\s+notice|automatic\s+reply|resposta\s+autom[áa]tica|out\s+of\s+office|aus[êe]ncia\s+do\s+escrit[óo]rio)\b/i',
            $s
        );
    }

    /** Status "de espera COM agendamento" cuja reativação por resposta do cliente guarda um snapshot
     *  (status + datas + pausa de SLA) para oferecer "voltar ao status anterior com a data". */
    private const REACTIVATION_SNAPSHOT_KEYS = ['planejamento_gmud', 'em_desenvolvimento', 'pendente_terceiros', 'em_homologacao'];

    /** Move o chamado para "Em andamento" quando o cliente responde por e-mail. Se estava RESOLVIDO,
     *  faz a reabertura (reopen_count/reopened_at) como no fluxo de recusa do portal. Se vinha de um
     *  status de espera agendado, grava o snapshot p/ o botão "voltar ao status anterior com a data". */
    private function moveToEmAndamento(HelpDeskTicket $t): void
    {
        $em = HelpDeskStatus::where('key', 'em_andamento')->first();
        if (!$em || $t->status_id === $em->id) return;
        $old = $t->status;
        $wasResolved = (bool) optional($old)->is_resolved;
        // Snapshot do estado anterior (só p/ os status marcados) ANTES de reativar — permite restaurar
        // status + datas + pausa de SLA exatamente como estava. Em outros status, nenhum snapshot.
        if (in_array(optional($old)->key, self::REACTIVATION_SNAPSHOT_KEYS, true)) {
            $t->reactivation_snapshot = [
                'status_id'         => (int) $old->id,
                'status_key'        => (string) $old->key,
                'status_label'      => (string) $old->label,
                'scheduled_until'   => optional($t->scheduled_until)->toIso8601String(),
                'scheduled_all_day' => (bool) $t->scheduled_all_day,
                'dev_delivery_at'   => $t->dev_delivery_at ? \Illuminate\Support\Carbon::parse($t->dev_delivery_at)->toDateString() : null,
                'sla_paused_at'     => optional($t->sla_paused_at)->toIso8601String(),
                // Pendente terceiros/TOTVS: guarda a justificativa e o fornecedor+nº do ticket p/ restaurar iguais.
                'justification_id'  => $t->justification_id ? (int) $t->justification_id : null,
                'justification_name' => (string) optional($t->justification)->name,
                'external_ticket_ref' => (string) $t->external_ticket_ref,
                'saved_at'          => now()->toIso8601String(),
            ];
        }
        $t->status_id = $em->id;
        // A justificativa (ex.: "TOTVS" do Pendente terceiros) é do status ANTERIOR — some ao reativar
        // (o chip não deve mostrar "Em atendimento/TOTVS"). O snapshot já a guardou p/ o restore.
        $t->justification_id = null;
        if ($wasResolved) {
            $t->reopened_at   = now();
            $t->resolved_at   = null;
            $t->reopen_count  = (int) $t->reopen_count + 1;
        }
        $this->sla->computeBreaches($t);
        $t->save();
        if ($wasResolved) {
            HelpDeskTicketEvent::log($t->id, 'reopened', ['to_value' => $em->label, 'meta' => ['via' => 'email']]);
        }
        HelpDeskTicketEvent::log($t->id, 'status_changed', ['field' => 'status', 'from_value' => optional($old)->key, 'to_value' => $em->key, 'meta' => ['via' => 'email']]);
    }

    /** Abre um chamado NOVO como continuação de um chamado ENCERRADO (resposta de e-mail a fechado).
     *  Liga por previous_ticket_id — a UI mostra o banner/botão de "continuação do chamado de origem". */
    private function openContinuationTicket(
        HelpDeskEmailAccount $acc, HelpDeskTicket $prev, string $subject, string $body,
        ?int $customerId, ?CustomerContact $contact, string $messageId, Carbon $receivedAt,
        string $fromEmail, string $fromName
    ): HelpDeskTicket {
        // Continuação: a descrição é SÓ a mensagem NOVA do cliente — corta o histórico citado do e-mail
        // (o chamado anterior fica acessível pela tarja amarela + "Ver chamado de origem", não embutido).
        $body = $this->stripQuotedHistory($body);
        return DB::transaction(function () use ($acc, $prev, $subject, $body, $customerId, $contact, $messageId, $receivedAt, $fromEmail, $fromName) {
            $t = HelpDeskTicket::create([
                'ticket_number'       => HelpDeskTicketNumber::next(),
                'previous_ticket_id'  => $prev->id,
                'subject'             => mb_substr($subject, 0, 195),
                'description'         => $body,
                'customer_id'         => $customerId ?? $prev->customer_id,
                'customer_contact_id' => $contact?->id ?? $prev->customer_contact_id,
                'requester_name'      => ($contact?->name ?: $fromName) ?: $prev->requester_name,
                'requester_email'     => $fromEmail ?: $prev->requester_email,
                'priority'            => $prev->priority ?: 'normal',
                // Continuação já nasce com a TRIAGEM do chamado anterior preenchida
                // (Categoria, Serviço, Nível, Contrato/Projeto) — EXCETO o RESPONSÁVEL (assignee fica vazio).
                'category_id'         => $prev->category_id,
                'service_id'          => $prev->service_id,
                'level'               => $prev->level,
                'contract_id'         => $prev->contract_id,
                'project_id'          => $prev->project_id,
                'channel'             => 'email',
                'status_id'           => optional(HelpDeskStatus::default())->id,
                'team_id'             => $prev->team_id ?: $acc->default_team_id,
                'graph_thread_msg_id' => $messageId, // âncora do threading (continuação)
                'source_system'       => 'email:graph',
                'external_ref'        => 'gm:' . substr(sha1($messageId), 0, 40),
                'last_activity_at'    => $receivedAt,
            ]);
            $this->sla->apply($t);
            HelpDeskTicketEvent::log($t->id, 'created', ['to_value' => $t->subject, 'meta' => ['via' => 'email', 'from' => $fromEmail, 'continua' => $prev->ticket_number]]);
            // Evento evidente no TIMELINE dos dois chamados (o "workflow" da continuação):
            //  - no chamado NOVO: aberto como continuação do chamado de origem (encerrado);
            //  - no chamado ANTERIOR: cliente respondeu após o encerramento → aberto o novo chamado.
            HelpDeskTicketEvent::log($t->id, 'continuacao_aberta', ['to_value' => $prev->ticket_number, 'meta' => ['via' => 'email', 'origem_id' => $prev->id]]);
            HelpDeskTicketEvent::log($prev->id, 'continuacao_gerada', ['to_value' => $t->ticket_number, 'meta' => ['via' => 'email', 'novo_id' => $t->id]]);
            return $t;
        });
    }

    /** Threading explícito: token [NUMERO] no assunto aponta direto pro chamado (qualquer formato). */
    private function matchByTicketToken(string $subject, ?int $companyId = null, ?string $fromEmail = null, ?int $customerId = null): ?HelpDeskTicket
    {
        // Aceita "[HD-000168]" (legado) e "[000169]" (novo formato Movidesk).
        // O número é único POR ORIGEM (origin_company_id) e NÃO muda na transferência de empresa →
        // a mesma caixa pode ver 2 chamados com o mesmo número (um nativo + um transferido).
        // Candidatos: chamados da empresa da caixa (atual OU de origem). Desempate: remetente
        // (requester/envolvidos) → cliente do remetente → empresa ATUAL = caixa → origem = caixa.
        if (!preg_match('/\[((?:[A-Za-z]{1,8}-)?\d{3,})\]/', $subject, $m)) return null;
        $q = fn () => HelpDeskTicket::withoutGlobalScopes()->whereNull('deleted_at')
            ->when($companyId, fn ($w) => $w->where(fn ($x) => $x->where('company_id', $companyId)->orWhere('origin_company_id', $companyId)));
        $cands = $q()->whereRaw('upper(ticket_number) = ?', [strtoupper($m[1])])->get();
        // Token legado "[BIZ-2790]" (antes de remover o prefixo) → procura o número cru.
        if ($cands->isEmpty() && preg_match('/^[A-Za-z]{1,8}-(\d+)$/', $m[1], $d)) {
            $cands = $q()->where('source_system', 'movidesk')->where('ticket_number', $d[1])->get();
        }
        if ($cands->count() <= 1) return $cands->first();
        $from = strtolower(trim((string) $fromEmail));
        $score = function (HelpDeskTicket $t) use ($from, $customerId, $companyId) {
            $s = 0;
            if ($from !== '') {
                if (strtolower(trim((string) $t->requester_email)) === $from) $s += 100;
                elseif (in_array($from, array_map(fn ($e) => strtolower(trim((string) $e)), (array) ($t->cc_emails ?? [])), true)) $s += 60;
            }
            if ($customerId && (int) $t->customer_id === $customerId) $s += 40;
            if ($companyId && (int) $t->company_id === $companyId) $s += 10;
            if ($companyId && (int) $t->origin_company_id === $companyId) $s += 5;
            return $s;
        };
        return $cands->sortByDesc(fn ($t) => $score($t) * 1000000000 + (int) $t->id)->first(); // empate → mais recente
    }

    /** Procura chamado aberto com o mesmo assunto (ignorando prefixos Re:/Fwd:). */
    private function findOpenTicketBySubject(string $subject, ?int $customerId): ?HelpDeskTicket
    {
        $clean = $this->stripReplyPrefix($subject);
        if ($clean === '') return null;

        // Compara sem prefixos de resposta dos DOIS lados: o assunto salvo também pode ter "Re:".
        return HelpDeskTicket::query()
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            // NÃO anexa em chamado de ORIGEM MOVIDESK — esses são geridos no Movidesk (a resposta
            // não volta pra lá). Threading por assunto só vale entre chamados NATIVOS do Minutor.
            ->where(fn ($q) => $q->whereNull('source_system')->orWhere('source_system', '!=', 'movidesk'))
            ->whereRaw("regexp_replace(lower(subject), '^((re|res|fwd|fw|enc)\\s*:\\s*)+', '') = ?", [mb_strtolower($clean)])
            ->whereHas('status', fn ($q) => $q->where('is_terminal', false))
            ->orderByDesc('id')
            ->first();
    }

    /** Remove prefixos de resposta/encaminhamento repetidos (Re:, Fwd:, Enc:, Res:, Fw:). */
    private function stripReplyPrefix(string $subject): string
    {
        $s = trim($subject);
        do {
            $prev = $s;
            $s = preg_replace('/^\s*(re|res|fwd|fw|enc)\s*:\s*/i', '', $s) ?? $s;
        } while ($s !== $prev);
        return trim($s);
    }

    /**
     * Embute as imagens inline referenciadas por cid no corpo (assinatura/logos) como data:,
     * preservando a aparência original ao renderizar. Devolve [corpoReescrito, anexosReais].
     *
     * @param array<int, array{name:string,mime:string,bytes:string,inline:bool,contentId:string}> $graphAtts
     * @return array{0: string, 1: array<int, array<string,mixed>>}
     */
    private function embedInlineImages(string $body, array $graphAtts): array
    {
        $regular = [];
        foreach ($graphAtts as $f) {
            $cid = (string) ($f['contentId'] ?? '');
            // Inline referenciado no corpo (cid:...) → embute como data: e NÃO vira anexo.
            if ($cid !== '' && str_contains($body, 'cid:' . $cid)) {
                $data = 'data:' . $f['mime'] . ';base64,' . base64_encode((string) $f['bytes']);
                $body = str_replace('cid:' . $cid, $data, $body);
                continue;
            }
            // Imagem marcada como inline pelo Graph (assinatura, logos, print colado no corpo) —
            // NÃO vira anexo mesmo sem casar o cid. Continua renderizando no corpo; só não duplica.
            if (!empty($f['inline'])) {
                continue;
            }
            $regular[] = $f; // anexo real (arquivo de fato enviado pelo usuário)
        }
        return [$body, $regular];
    }

    /**
     * Registra os anexos REAIS (não-inline) no chamado/resposta via AttachmentService.
     *
     * @param array<int, array<string,mixed>> $files
     */
    private function storeFiles(string $entityType, int $entityId, array $files): void
    {
        if (empty($files)) return;
        $actor = $this->systemActor();
        if (!$actor) return;

        foreach ($files as $file) {
            try {
                $name     = $this->safeFileName((string) $file['name']);
                $category = str_starts_with((string) $file['mime'], 'image/') ? 'image' : 'attachment';
                $key      = sprintf('attachments/%s/%d/%s_%s', strtolower($entityType), $entityId, substr((string) Str::uuid(), 0, 8), $name);

                $this->storage->put($key, (string) $file['bytes'], (string) $file['mime']);
                $this->attachments->registerExisting($actor, [
                    'entity_type'   => $entityType,
                    'entity_id'     => $entityId,
                    'category'      => $category,
                    'storage_path'  => $key,
                    'original_name' => $name,
                    'mime_type'     => (string) $file['mime'],
                    'metadata'      => ['source' => 'email:graph'],
                ]);
            } catch (\Throwable $e) {
                Log::warning("HelpDesk: falha ao salvar anexo de e-mail ({$entityType} #{$entityId}): " . $e->getMessage());
            }
        }
    }

    /** Nome de arquivo seguro com extensão (default .bin). */
    private function safeFileName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($name)) ?: 'anexo';
        if (!str_contains($name, '.')) $name .= '.bin';
        return mb_substr($name, 0, 120);
    }

    /** Ator do sistema p/ uploaded_by dos anexos recebidos (1º admin; senão 1º usuário). */
    private function systemActor(): ?User
    {
        return $this->actor ??= (User::where('type', 'admin')->orderBy('id')->first() ?? User::orderBy('id')->first());
    }

    /**
     * Pré-cadastro de usuário cliente a partir do e-mail (fase 1a).
     *
     * Cria um `users` type=cliente, vinculado à empresa (customer) identificada pelo
     * domínio, com acesso SÓ ao Help Desk (allowed_modules=['help_desk']) e SEM senha
     * (coluna nullable) — `enabled=false`. Não loga até o convite (fase 1b) definir senha.
     *
     * Idempotente por e-mail: se já existe QUALQUER usuário com esse e-mail (cliente ou
     * interno), não faz nada — nunca sobrescreve. Envolto em try/catch: falha aqui não
     * pode derrubar a ingestão do e-mail/ticket.
     */
    private function preRegisterClientUser(string $email, ?string $name, int $customerId, ?HelpDeskAssociationRule $rule): void
    {
        try {
            if (User::where('email', $email)->exists()) {
                return; // já existe — não mexe (inclusive usuários internos)
            }

            $companyId = $rule?->company_id ?? 1;
            // Perfil: regra de associação → senão o PADRÃO DO CLIENTE (customers.helpdesk_default_access_profile_id).
            // (Antes caía no default GLOBAL por tipo; agora é definido por cliente.)
            $profileId = $rule?->access_profile_id
                ?? optional(\App\Models\Customer::find($customerId))->helpdesk_default_access_profile_id;

            $user = new User();
            $user->forceFill([
                'name'                       => $name ?: $email,
                'email'                      => $email,
                'type'                       => 'cliente',
                'customer_id'                => $customerId,
                'allowed_modules'            => ['help_desk'],
                'helpdesk_access_profile_id' => $profileId,
                'home_company_id'            => $companyId,
                'current_company_id'         => $companyId,
                'enabled'                    => false, // pendente: sem senha, aguarda convite (1b)
                // password OMITIDO => NULL (coluna nullable) => verifyPassword() barra o login
            ])->save();

            \Log::info('🆕 [HD INGEST] Usuário cliente pré-cadastrado (sem senha)', [
                'user_id' => $user->id, 'email' => $email, 'customer_id' => $customerId,
                'company_id' => $companyId, 'helpdesk_access_profile_id' => $profileId,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('⚠️ [HD INGEST] Falha ao pré-cadastrar usuário cliente', [
                'email' => $email, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Corta o histórico citado das respostas de e-mail: quando o cliente responde a um e-mail de
     * atualização, tudo a partir do marcador "não escreva abaixo desta linha" é o e-mail anterior
     * (nosso cabeçalho + thread) e deve ser descartado — só o texto NOVO (acima) vira interação.
     * Abertura nova (sem o marcador) é no-op.
     */
    private function stripQuotedHistory(string $html): string
    {
        if ($html === '') return $html;
        // Corta no PRIMEIRO marcador de "início do histórico citado" que aparecer. Além do nosso
        // "não escreva abaixo desta linha", inclui os separadores do Outlook (desktop/web/mobile),
        // que empurravam a thread inteira (com imagens base64) para a descrição da continuação.
        $markers = [
            '/n[aã]o\s+escreva\s+abaixo\s+desta\s+linha/iu',
            '/<div\b[^>]*\bid=["\']appendonsend["\']/i',                 // Outlook web/desktop: início do original
            '/<div\b[^>]*\bid=["\']divRplyFwdMsg["\']/i',               // Outlook: bloco "De:/Enviada:/Para:"
            '/<div\b[^>]*\bid=["\']mail-editor-reference-message-container["\']/i',
            '/<hr\b[^>]*\bid=["\']stopSpelling["\']/i',                 // Outlook desktop: <hr> antes do original
            '/<blockquote\b/i',                                          // citação genérica (Gmail/Apple)
        ];
        $cutAt = null;
        foreach ($markers as $re) {
            if (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE)) {
                $pos = (int) $m[0][1];
                if ($cutAt === null || $pos < $cutAt) $cutAt = $pos;
            }
        }
        if ($cutAt !== null) {
            $cut = substr($html, 0, $cutAt);
            // recua até o início da tag que contém o marcador, p/ não deixar tag meia-aberta
            $lastLt = strrpos($cut, '<');
            if ($lastLt !== false && strpos($cut, '>', $lastLt) === false) {
                $cut = substr($cut, 0, $lastLt);
            }
            $html = rtrim($cut);
        }
        return $html;
    }

    private function ledger(
        HelpDeskEmailAccount $acc, string $messageId, ?string $fromEmail, string $subject,
        string $action, ?string $reason, Carbon $receivedAt, array &$sum,
        ?int $ticketId = null, ?int $commentId = null
    ): void {
        HelpDeskIngestedEmail::create([
            'email_account_id' => $acc->id,
            'graph_message_id' => $messageId,
            'from_email'       => $fromEmail ? mb_substr($fromEmail, 0, 190) : null,
            'subject'          => mb_substr($subject, 0, 300),
            'action'           => $action,
            'reason'           => $reason,
            'ticket_id'        => $ticketId,
            'comment_id'       => $commentId,
            'received_at'      => $receivedAt,
        ]);
        $this->lastLedgerAction = $action;
        if ($action === 'ignored') $sum['ignored']++;
    }
}
