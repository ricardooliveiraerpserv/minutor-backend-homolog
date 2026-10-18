<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\ConectaTicketMigrationLog;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Empurra a CÓPIA de um ticket de HD da ERPSERV para a Conecta (POST /api/v1/migration/ticket)
 * e registra em conecta_ticket_migration_log. Idempotente por (source_system, external_ref) —
 * a Conecta também faz upsert, então re-tentar não duplica. Ver
 * [[project_minutor_conecta_client_migration]].
 */
class ConectaTicketPusher
{
    /** Empurra a cópia de um ticket EXISTENTE (model) — usado no backfill do legado. */
    public function push(HelpDeskTicket $ticket, string $kind = 'legado'): ConectaTicketMigrationLog
    {
        [$ss, $ref] = $this->sourceRef($ticket);

        $log = ConectaTicketMigrationLog::firstOrNew(['source_system' => $ss, 'external_ref' => $ref]);
        $log->customer_id       = $ticket->customer_id;
        $log->erpserv_ticket_id = $ticket->id;
        $log->movidesk_id       = $ticket->source_system === 'movidesk' ? (string) $ticket->external_ref : null;
        $log->subject           = mb_substr((string) $ticket->subject, 0, 255);
        $log->status_text       = optional($ticket->status)->label;
        $log->kind              = $kind;

        return $this->sendAndLog($this->buildPayload($ticket, $ss, $ref), $log);
    }

    /**
     * Empurra um ticket montado na mão (sem model na ERPSERV) — usado p/ "novos" do portal de
     * cliente migrado (o ticket NÃO é criado aqui; nasce na Conecta). $payload no mesmo formato
     * aceito pela FA; $logMeta preenche customer_id/subject etc. do log.
     */
    public function pushRaw(array $payload, array $logMeta = []): ConectaTicketMigrationLog
    {
        $ss  = (string) ($payload['ticket']['source_system'] ?? 'erpserv');
        $ref = (string) ($payload['ticket']['external_ref'] ?? '');

        $log = ConectaTicketMigrationLog::firstOrNew(['source_system' => $ss, 'external_ref' => $ref]);
        $log->fill($logMeta);
        $log->kind    = $logMeta['kind'] ?? 'novo';
        $log->subject = $log->subject ?: mb_substr((string) ($payload['ticket']['subject'] ?? ''), 0, 255);

        return $this->sendAndLog($payload, $log);
    }

    /** POST na FA + atualização do log (ok/erro). Centraliza auth, timeout e registro. */
    private function sendAndLog(array $payload, ConectaTicketMigrationLog $log): ConectaTicketMigrationLog
    {
        $log->attempts = (int) $log->attempts + 1;

        $url    = rtrim((string) config('services.conecta.api_url', ''), '/');
        $secret = (string) config('services.conecta.migration_secret', '');
        if ($url === '' || $secret === '') {
            $log->result    = 'error';
            $log->error_msg = 'Integração Conecta não configurada (CONECTA_API_URL / CONECTA_MIGRATION_SECRET).';
            $log->save();
            return $log;
        }

        try {
            $resp = Http::withHeaders(['X-Migration-Secret' => $secret])
                ->acceptJson()->timeout(120)
                ->post("{$url}/api/v1/migration/ticket", $payload);

            if ($resp->successful()) {
                $body = $resp->json() ?: [];
                $log->result            = 'ok';
                $log->error_msg         = null;
                $log->conecta_ticket_id = $body['conecta_ticket_id'] ?? null;
                $log->comments_sent     = $body['comments_created'] ?? null;
                $log->attachments_sent  = $body['attachments_stored'] ?? null;
                $log->sent_at           = now();
            } else {
                $log->result    = 'error';
                $log->error_msg = 'HTTP ' . $resp->status() . ': ' . mb_substr((string) $resp->body(), 0, 1000);
            }
        } catch (\Throwable $e) {
            $log->result    = 'error';
            $log->error_msg = mb_substr($e->getMessage(), 0, 1000);
            Log::warning('[ConectaTicketPusher] falha', ['source_ref' => $log->source_system . '/' . $log->external_ref, 'err' => $e->getMessage()]);
        }

        $log->save();
        return $log;
    }

    /** Chave estável: movidesk mantém (source=movidesk, ref=id Movidesk); nativo vira (erpserv, erpserv-<id>). */
    public function sourceRef(HelpDeskTicket $ticket): array
    {
        if ($ticket->source_system === 'movidesk' && $ticket->external_ref) {
            return ['movidesk', (string) $ticket->external_ref];
        }
        return ['erpserv', 'erpserv-' . $ticket->id];
    }

    private function buildPayload(HelpDeskTicket $ticket, string $ss, string $ref): array
    {
        $customer = $ticket->customer;
        $comments = $ticket->comments()->orderBy('created_at')->orderBy('id')->get();

        return [
            'customer_cgc'  => optional($customer)->cgc,
            'customer_name' => optional($customer)->name ?: $ticket->historical_customer_name,
            'ticket' => [
                'source_system'    => $ss,
                'external_ref'     => $ref,
                'ticket_number'    => (string) ($ticket->ticket_number ?? $ref),
                'subject'          => (string) $ticket->subject,
                'description'      => (string) $ticket->description,
                'status_text'      => optional($ticket->status)->label,
                'status_key'       => optional($ticket->status)->key,
                'priority'         => $ticket->priority,
                'channel'          => $ticket->channel,
                'requester_name'   => $ticket->requester_name,
                'requester_email'  => $ticket->requester_email,
                'assignee_email'   => optional($ticket->assignee)->email ?: $ticket->external_owner_email,
                'assignee_name'    => optional($ticket->assignee)->name,
                'category_name'    => optional($ticket->category)->name ?: $ticket->external_category,
                'service_name'     => optional($ticket->service)->name ?: $ticket->external_service,
                'cc_emails'        => $ticket->cc_emails,
                'created_at'        => optional($ticket->created_at)->toIso8601String(),
                'last_activity_at'  => optional($ticket->last_activity_at)->toIso8601String(),
                'first_responded_at' => optional($ticket->first_responded_at)->toIso8601String(),
                'resolved_at'       => optional($ticket->resolved_at)->toIso8601String(),
                'closed_at'         => optional($ticket->closed_at)->toIso8601String(),
                'reopened_at'       => optional($ticket->reopened_at)->toIso8601String(),
            ],
            'comments' => $comments->map(fn (HelpDeskTicketComment $c) => [
                'external_action_id' => $c->external_action_id,
                'body'               => (string) $c->body,
                'visibility'         => $c->visibility,
                'is_system'          => (bool) $c->is_system,
                'channel'            => $c->channel,
                'created_at'         => optional($c->created_at)->toIso8601String(),
                'attachments'        => $this->attachmentsFor('HELPDESK_TICKET_COMMENT', (int) $c->id),
            ])->all(),
            'attachments' => $this->attachmentsFor('HELPDESK_TICKET', (int) $ticket->id),
        ];
    }

    /** Lê os anexos locais da entidade e serializa em base64. Best-effort: arquivo sumido é pulado. */
    private function attachmentsFor(string $entityType, int $entityId): array
    {
        $out  = [];
        $atts = Attachment::where('entity_type', $entityType)->where('entity_id', $entityId)->get();
        if ($atts->isEmpty()) {
            return $out;
        }
        $storage = app(\App\Attachments\Storage\StorageProvider::class);
        foreach ($atts as $att) {
            try {
                $bytes = $storage->get((string) $att->storage_path);
            } catch (\Throwable $e) {
                Log::info('[ConectaTicketPusher] anexo indisponível (pulado)', ['att' => $att->id, 'err' => $e->getMessage()]);
                continue;
            }
            $out[] = [
                'original_name' => (string) ($att->original_name ?: ('arquivo-' . $att->id)),
                'mime'          => (string) ($att->mime_type ?: 'application/octet-stream'),
                'category'      => (string) ($att->category ?: 'attachment'),
                'base64'        => base64_encode($bytes),
            ];
        }
        return $out;
    }
}
