<?php

namespace App\Services;

use App\Attachments\Storage\StorageProvider;
use App\Models\Attachment;
use App\Models\HelpDeskEmailAccount;
use App\Models\HelpDeskIngestedEmail;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use Illuminate\Support\Facades\Log;

/**
 * Envio das RESPOSTAS PÚBLICAS do chamado por e-mail ao solicitante, pelo mesmo OAuth/Graph
 * do recebimento (GraphMailSender · Mail.Send). Threading garantido pelo token [HD-xxxxxx]
 * no assunto — a resposta do cliente volta e o ingestor recola no mesmo chamado.
 */
class HelpDeskReplyMailer
{
    /**
     * Tenta enviar a resposta pública por e-mail. Retorna [ok, motivo|erro].
     * NUNCA lança: a falha de e-mail não pode derrubar a gravação do comentário.
     *
     * @return array{0: bool, 1: ?string}
     */
    public static function sendPublicComment(HelpDeskTicket $ticket, HelpDeskTicketComment $comment): array
    {
        // Só resposta pública feita por um AGENTE (usuário). Inbound (author_contact_id) não reenvia.
        if ($comment->visibility !== 'customer' || empty($comment->author_user_id)) {
            return [false, 'skip:nao_e_resposta_publica_de_agente'];
        }
        if (trim(strip_tags((string) $comment->body)) === '') {
            return [false, 'skip:corpo_vazio'];
        }
        if (!GraphMailSender::enabled()) {
            return [false, 'skip:graph_desligado'];
        }

        $from = self::resolveFromAccount($ticket);
        if (!$from) return [false, 'skip:sem_conta_microsoft365'];

        $to = self::resolveRecipient($ticket);
        if (!$to) return [false, 'skip:sem_destinatario'];

        // Trata o corpo: prints colados (data:image) viram imagens inline (cid) — data: é
        // bloqueado pela maioria dos clientes de e-mail — e o HTML é envelopado.
        [$html, $inlineImgs] = self::treatBody((string) $comment->body);
        $attachments = array_merge($inlineImgs, self::commentAttachments($comment));

        $cc = array_values(array_filter((array) $ticket->cc_emails, fn ($e) => $e && strcasecmp($e, $to) !== 0));
        [$ok, $err] = GraphMailSender::sendAs(
            (string) $from->email, [$to], $cc, self::subjectFor($ticket), $html, [], $attachments
        );
        if (!$ok) {
            Log::warning("HelpDesk: e-mail de resposta falhou ({$ticket->ticket_number} → {$to}): {$err}");
        }
        return [$ok, $err];
    }

    /**
     * Trata o corpo da resposta p/ envio por e-mail:
     *  - extrai prints colados (<img src="data:image/...;base64,...">) e os converte em
     *    imagens INLINE (cid) — data: URIs são bloqueados pela maioria dos clientes;
     *  - envelopa num HTML mínimo com fonte legível.
     *
     * @return array{0: string, 1: array<int, array{name:string, mime:string, bytes:string, cid:string}>}
     */
    private static function treatBody(string $html): array
    {
        $inline = [];
        $i = 0;
        $html = preg_replace_callback(
            '/<img\b[^>]*\bsrc=["\']data:(image\/[a-zA-Z0-9.+-]+);base64,([^"\']+)["\'][^>]*>/i',
            function ($m) use (&$inline, &$i) {
                $bytes = base64_decode($m[2], true);
                if ($bytes === false || $bytes === '') return '';
                $i++;
                $mime = strtolower($m[1]);
                $ext  = match ($mime) {
                    'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
                    'image/gif' => 'gif', 'image/webp' => 'webp', default => 'img',
                };
                $cid = "hdimg{$i}@minutor";
                $inline[] = ['name' => "imagem{$i}.{$ext}", 'mime' => $mime, 'bytes' => $bytes, 'cid' => $cid];
                return '<img src="cid:' . $cid . '" style="max-width:100%;height:auto">';
            },
            $html
        ) ?? $html;

        $wrapped = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111827;line-height:1.5">'
            . $html . '</div>';

        return [$wrapped, $inline];
    }

    /**
     * Anexos da resposta como blobs em memória, pro envio via Graph.
     *
     * @return array<int, array{name:string, mime:string, bytes:string}>
     */
    private static function commentAttachments(HelpDeskTicketComment $comment): array
    {
        $atts = Attachment::query()
            ->forEntity('HELPDESK_TICKET_COMMENT', $comment->id)
            ->whereNull('deleted_at')->get();
        if ($atts->isEmpty()) return [];

        $storage = app(StorageProvider::class);
        $out = [];
        foreach ($atts as $att) {
            try {
                $out[] = ['name' => (string) $att->original_name, 'mime' => (string) $att->mime_type, 'bytes' => $storage->get($att->storage_path)];
            } catch (\Throwable $e) {
                Log::warning("HelpDesk: falha ao ler anexo #{$att->id} p/ e-mail: " . $e->getMessage());
            }
        }
        return $out;
    }

    /** Conta microsoft365 que envia: a da fila do chamado; senão, a primeira habilitada. */
    private static function resolveFromAccount(HelpDeskTicket $ticket): ?HelpDeskEmailAccount
    {
        $base = HelpDeskEmailAccount::where('provider', 'microsoft365')->where('enabled', true);
        if ($ticket->team_id) {
            $match = (clone $base)->where('default_team_id', $ticket->team_id)->first();
            if ($match) return $match;
        }
        return $base->orderBy('id')->first();
    }

    /** Para quem responder: contato do chamado → remetente original (ledger) → usuário solicitante. */
    private static function resolveRecipient(HelpDeskTicket $ticket): ?string
    {
        if ($email = optional($ticket->contact)->email) return $email;

        $led = HelpDeskIngestedEmail::where('ticket_id', $ticket->id)
            ->whereNotNull('from_email')->orderBy('id')->first();
        if ($led?->from_email) return $led->from_email;

        return optional($ticket->requester)->email;
    }

    /** Assunto com token [HD-xxxxxx] + prefixo Re: — garante o threading no retorno. */
    public static function subjectFor(HelpDeskTicket $ticket): string
    {
        $token = "[{$ticket->ticket_number}]";
        $subj  = (string) $ticket->subject;
        if (!str_contains($subj, $token)) $subj = "{$token} {$subj}";
        if (!preg_match('/^\s*re\s*:/i', $subj)) $subj = "Re: {$subj}";
        return mb_substr($subj, 0, 250);
    }
}
