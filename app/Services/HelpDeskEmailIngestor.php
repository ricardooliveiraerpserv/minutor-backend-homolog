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
            $messages = GraphMailReader::recentMessages((string) $acc->email, $perAccount);
            // Mais antigas primeiro, p/ manter a ordem cronológica das respostas.
            $messages = array_reverse($messages);

            foreach ($messages as $msg) {
                $sum['fetched']++;
                try {
                    $this->processOne($acc, $msg, $sum);
                } catch (\Throwable $e) {
                    $sum['errors']++;
                    $sum['details'][] = "Conta #{$acc->id}: erro em '" . ($msg['id'] ?? '?') . "': " . $e->getMessage();
                }
            }
        }

        return $sum;
    }

    /** @param array<string,mixed> $msg */
    private function processOne(HelpDeskEmailAccount $acc, array $msg, array &$sum): void
    {
        $messageId = (string) ($msg['id'] ?? '');
        if ($messageId === '') return;

        // Dedup: já processado?
        if (HelpDeskIngestedEmail::where('email_account_id', $acc->id)->where('graph_message_id', $messageId)->exists()) {
            return;
        }

        $fromEmail = strtolower(trim((string) data_get($msg, 'from.emailAddress.address', '')));
        $fromName  = trim((string) data_get($msg, 'from.emailAddress.name', '')) ?: $fromEmail;
        $subject   = trim((string) ($msg['subject'] ?? '')) ?: '(sem assunto)';
        $body      = (string) (data_get($msg, 'body.content') ?: data_get($msg, 'bodyPreview', ''));
        $receivedAt = ($r = data_get($msg, 'receivedDateTime')) ? Carbon::parse($r) : now();

        // Anti-loop: ignora e-mail enviado pela própria caixa (cópia/auto-resposta).
        if ($fromEmail !== '' && strcasecmp($fromEmail, (string) $acc->email) === 0) {
            $this->ledger($acc, $messageId, $fromEmail, $subject, 'ignored', 'auto_da_propria_caixa', $receivedAt, $sum);
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
        $existing = $this->matchByTicketToken($subject);
        if (!$existing && $appendExisting && $associateExisting !== 'new') {
            $existing = $this->findOpenTicketBySubject($subject, $associateExisting === 'customer' ? $customerId : null);
        }
        if ($existing) {
            $comment = DB::transaction(function () use ($existing, $body, $contact, $messageId, $receivedAt) {
                $c = $existing->comments()->create([
                    'author_contact_id' => $contact?->id,
                    'body'              => $body,
                    'visibility'        => 'customer',
                    'channel'           => 'email',
                    'idempotency_key'   => 'email:' . substr(sha1($messageId), 0, 40), // id do Graph não cabe em varchar(80)
                ]);
                $existing->update(['last_activity_at' => $receivedAt]);
                HelpDeskTicketEvent::log($existing->id, 'comment', ['meta' => ['comment_id' => $c->id, 'visibility' => 'customer', 'via' => 'email']]);
                return $c;
            });
            $sum['comments']++;
            $this->storeFiles('HELPDESK_TICKET_COMMENT', $comment->id, $inboundFiles);
            HelpDeskTriggerEngine::dispatch('comment_added', $existing->fresh(), ['comment_by' => 'client', 'actor_email' => $fromEmail]);
            $sum['details'][] = "↩︎ Resposta anexada ao {$existing->ticket_number} (de {$fromEmail}).";
            $this->ledger($acc, $messageId, $fromEmail, $subject, 'comment_appended', null, $receivedAt, $sum, $existing->id, $comment->id);
            return;
        }

        // Abre chamado novo — mesmo fluxo do store() oficial.
        $ticket = DB::transaction(function () use ($acc, $subject, $body, $customerId, $contact, $messageId, $receivedAt, $fromEmail, $fromName) {
            $t = HelpDeskTicket::create([
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
                'source_system'       => 'email:graph',
                // id do Graph é longo (>120); guardamos um hash curto aqui e o id completo no ledger.
                'external_ref'        => 'gm:' . substr(sha1($messageId), 0, 40),
                'last_activity_at'    => $receivedAt,
            ]);
            $t->update(['ticket_number' => 'HD-' . str_pad((string) $t->id, 6, '0', STR_PAD_LEFT)]);
            $this->sla->apply($t);
            HelpDeskTicketEvent::log($t->id, 'created', ['to_value' => $t->subject, 'meta' => ['via' => 'email', 'from' => $fromEmail]]);
            return $t;
        });
        $sum['tickets']++;
        $this->storeFiles('HELPDESK_TICKET', $ticket->id, $inboundFiles);
        HelpDeskTriggerEngine::dispatch('ticket_created', $ticket->fresh(), ['comment_by' => 'client', 'actor_email' => $fromEmail]);
        $sum['details'][] = "✅ Chamado {$ticket->ticket_number} aberto de {$fromEmail}: \"{$subject}\".";
        $this->ledger($acc, $messageId, $fromEmail, $subject, 'ticket_created', null, $receivedAt, $sum, $ticket->id);
    }

    /** Threading explícito: token [NUMERO] no assunto aponta direto pro chamado (qualquer formato). */
    private function matchByTicketToken(string $subject): ?HelpDeskTicket
    {
        // Aceita "[HD-000168]" (legado) e "[000169]" (novo formato Movidesk).
        if (preg_match('/\[((?:[A-Za-z]{1,8}-)?\d{3,})\]/', $subject, $m)) {
            return HelpDeskTicket::whereRaw('upper(ticket_number) = ?', [strtoupper($m[1])])->first();
        }
        return null;
    }

    /** Procura chamado aberto com o mesmo assunto (ignorando prefixos Re:/Fwd:). */
    private function findOpenTicketBySubject(string $subject, ?int $customerId): ?HelpDeskTicket
    {
        $clean = $this->stripReplyPrefix($subject);
        if ($clean === '') return null;

        // Compara sem prefixos de resposta dos DOIS lados: o assunto salvo também pode ter "Re:".
        return HelpDeskTicket::query()
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
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
            $regular[] = $f; // anexo real (arquivo enviado pelo usuário)
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
        if ($action === 'ignored') $sum['ignored']++;
    }
}
