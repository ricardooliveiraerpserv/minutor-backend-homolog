<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Envio de e-mail do Help Desk via Microsoft Graph, usando A MESMA autenticação do
 * recebimento (config('services.graph_reader') = mesmo app/credenciais do GraphMailReader,
 * permissão de aplicação Mail.Send). É o par de saída do provedor microsoft365: sem SMTP,
 * sem senha — coerente com o recebimento OAuth.
 *
 * Compartilha o cache de token com o leitor (mesma chave 'graph_reader_token', mesmo escopo
 * .default) — literalmente a mesma autenticação. Separado do GraphMailer (envio do sistema /
 * fechamentos, que usa services.graph) p/ NÃO acoplar os dois nem ligar Graph global na Replica.
 */
class GraphMailSender
{
    private const TOKEN_URL  = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    /** true só quando as 3 credenciais (mesmas do leitor) estão preenchidas. */
    public static function enabled(): bool
    {
        return GraphMailReader::enabled();
    }

    /**
     * Allowlist de destinatários (MAIL_ALLOWLIST, e-mails separados por vírgula). Quando preenchida,
     * NENHUM e-mail sai p/ fora dela — trava de segurança da Replica p/ não notificar gente real.
     * Vazia (prod) = sem restrição.
     *
     * @return array<int,string> em minúsculas; [] = sem allowlist
     */
    public static function allowlist(): array
    {
        $raw = (string) env('MAIL_ALLOWLIST', '');
        if (trim($raw) === '') return [];
        return array_values(array_filter(array_map(
            fn ($e) => mb_strtolower(trim($e)),
            explode(',', $raw)
        ), fn ($e) => $e !== ''));
    }

    /** Token client-credentials — MESMA auth/cache do leitor. */
    protected static function token(): string
    {
        $c = config('services.graph_reader');

        return Cache::remember('graph_reader_token', now()->addMinutes(50), function () use ($c) {
            $resp = Http::asForm()->post(sprintf(self::TOKEN_URL, $c['tenant_id']), [
                'grant_type'    => 'client_credentials',
                'client_id'     => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'scope'         => 'https://graph.microsoft.com/.default',
            ]);
            if (!$resp->successful()) {
                throw new \RuntimeException('Graph: falha ao obter token (HTTP ' . $resp->status() . '): ' . $resp->body());
            }
            $token = $resp->json('access_token');
            if (empty($token)) throw new \RuntimeException('Graph: token de acesso vazio.');
            return $token;
        });
    }

    /**
     * Envia um e-mail HTML COMO a caixa $fromEmail (a própria conta do Help Desk).
     * Retorna [ok, erro|null]. Reúsa o builder puro do GraphMailer (zero duplicação do payload).
     *
     * @param array<int,string> $to
     * @param array<int,string> $cc
     * @param array<int,string> $attachmentPaths            anexos por caminho local
     * @param array<int,array{name:string,mime:string,bytes:string,cid?:string}> $inlineAttachments  anexos em memória (cid = imagem inline)
     * @return array{0: bool, 1: ?string}
     */
    public static function sendAs(string $fromEmail, array $to, array $cc, string $subject, string $htmlBody, array $attachmentPaths = [], array $inlineAttachments = [], bool $withFooter = true, array $bcc = [], ?string $threadAnchorId = null): array
    {
        if (!self::enabled()) {
            return [false, 'Microsoft Graph (envio) não configurado no servidor.'];
        }

        // TRAVA DE AMBIENTE (Replica): se MAIL_ALLOWLIST estiver setada, só envia p/ esses e-mails.
        // Filtra to/cc/bcc; qualquer outro destinatário é descartado. Protege TODO envio, não só lembretes.
        if ($allow = self::allowlist()) {
            $keep = fn (array $list) => array_values(array_filter(
                array_map('trim', $list),
                fn ($e) => $e !== '' && in_array(mb_strtolower($e), $allow, true)
            ));
            $to = $keep($to); $cc = $keep($cc); $bcc = $keep($bcc);
            if (empty($to) && empty($cc) && empty($bcc)) {
                return [true, null];   // ninguém na allowlist → silenciosamente não envia (sucesso "no-op")
            }
        }

        // Rodapé/assinatura padrão (logo ERPSERV inline + Minutor discreto).
        if ($withFooter) {
            $htmlBody .= HelpDeskMailFooter::html('cid:' . HelpDeskMailFooter::LOGO_CID);
            if ($logo = HelpDeskMailFooter::inlineLogo()) $inlineAttachments[] = $logo;
        }

        try {
            $message = GraphMailer::buildMessage($subject, $htmlBody, $to, $cc, $attachmentPaths);

            // BCC (envio único p/ muitos destinatários — ex.: notificação a um grupo).
            $bccClean = array_values(array_filter(array_map('trim', $bcc), fn ($e) => $e !== ''));
            if (!empty($bccClean)) {
                $message['bccRecipients'] = array_map(fn ($e) => ['emailAddress' => ['address' => $e]], $bccClean);
            }

            // Anexos em memória. Até 25 MB POR ARQUIVO (createUploadSession p/ > ~3 MB). Imagens
            // inline (cid) são pequenas (prints reduzidos) e vão como contentBytes no corpo.
            $largeAtts = [];
            foreach ($inlineAttachments as $a) {
                $bytes = (string) ($a['bytes'] ?? '');
                if ($bytes === '') continue;
                $len = strlen($bytes);
                if ($len > GraphMailer::MAX_ATTACHMENT_BYTES) {
                    $mb = round($len / 1048576, 1);
                    return [false, "O anexo '" . ($a['name'] ?? 'arquivo') . "' tem {$mb} MB; o limite por arquivo é 25 MB."];
                }
                $isCid = !empty($a['cid']);
                if ($isCid || $len <= GraphMailer::MAX_INLINE_ATTACHMENTS_BYTES) {
                    // pequeno / inline → contentBytes direto (um POST simples aguenta)
                    $entry = [
                        '@odata.type'  => '#microsoft.graph.fileAttachment',
                        'name'         => (string) ($a['name'] ?? 'anexo'),
                        'contentType'  => (string) ($a['mime'] ?? 'application/octet-stream'),
                        'contentBytes' => base64_encode($bytes),
                    ];
                    if ($isCid) { $entry['contentId'] = (string) $a['cid']; $entry['isInline'] = true; }
                    $message['attachments'][] = $entry;
                } else {
                    // grande (>3 MB) → só entra via upload session, num rascunho
                    $largeAtts[] = ['name' => (string) ($a['name'] ?? 'anexo'), 'mime' => (string) ($a['mime'] ?? 'application/octet-stream'), 'bytes' => $bytes];
                }
            }

            $token = self::token();

            // THREADING: com uma âncora (a mensagem do cliente no chamado), envia como RESPOSTA do
            // Graph (createReply) → cai na MESMA conversa na caixa do cliente ("e-mail único" com
            // histórico) em vez de mensagem solta. Qualquer falha no fluxo → fallback pro sendMail.
            if ($threadAnchorId) {
                [$tOk, $tErr] = self::sendThreadedReply($token, $fromEmail, (string) $threadAnchorId, $message, $largeAtts);
                if ($tOk) return [true, null];
                \Illuminate\Support\Facades\Log::info("HelpDesk: threading indisponível ({$tErr}); enviando sem thread.");
            }

            // Sem thread mas COM anexo grande → precisa de rascunho + upload session (sendMail inline nao passa de ~3MB).
            if (!empty($largeAtts)) {
                return self::sendViaDraft($token, $fromEmail, $message, $largeAtts);
            }

            $url  = sprintf('%s/users/%s/sendMail', self::GRAPH_BASE, rawurlencode($fromEmail));
            $resp = Http::withToken($token)->acceptJson()->asJson()
                ->post($url, ['message' => $message, 'saveToSentItems' => true]);

            if ($resp->successful()) return [true, null];

            $msg = $resp->json('error.message') ?: ('HTTP ' . $resp->status());
            if ($resp->status() === 403) {
                $msg = "Sem permissão Mail.Send para '{$fromEmail}' (confira a permissão de aplicativo e a Application Access Policy no Azure). ({$msg})";
            }
            return [false, $msg];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Envia $message (já montado) como RESPOSTA (createReply) à mensagem âncora do cliente,
     * mantendo a MESMA conversa (conversationId/References) na caixa dele. Passos: cria o
     * rascunho de resposta → sobrescreve assunto/corpo/destinatários → anexa arquivos → envia.
     * Retorna [ok, erro]; qualquer falha vira [false, motivo] e o chamador faz fallback p/ sendMail.
     *
     * @param array<string,mixed> $message
     * @return array{0: bool, 1: ?string}
     */
    private static function sendThreadedReply(string $token, string $fromEmail, string $anchorId, array $message, array $largeAtts = []): array
    {
        try {
            $base   = sprintf('%s/users/%s', self::GRAPH_BASE, rawurlencode($fromEmail));
            $anchor = $base . '/messages/' . rawurlencode($anchorId);

            // 1) rascunho de resposta NA MESMA conversa (createReply herda conversationId/References)
            $r = Http::withToken($token)->acceptJson()->withBody('{}', 'application/json')->post($anchor . '/createReply');
            if (!$r->successful()) return [false, 'createReply HTTP ' . $r->status()];
            $draftId = (string) $r->json('id');
            if ($draftId === '') return [false, 'createReply sem id'];
            $draft = $base . '/messages/' . rawurlencode($draftId);

            // 2) sobrescreve o conteúdo (o corpo do createReply — original citado — é trocado pelo nosso)
            $patch = Http::withToken($token)->acceptJson()->asJson()->patch($draft, [
                'subject'      => (string) ($message['subject'] ?? ''),
                'body'         => $message['body'] ?? ['contentType' => 'HTML', 'content' => ''],
                'toRecipients' => $message['toRecipients'] ?? [],
                'ccRecipients' => $message['ccRecipients'] ?? [],
            ]);
            if (!$patch->successful()) return [false, 'patch HTTP ' . $patch->status()];

            // 3) anexos pequenos/inline (contentBytes) — um POST por anexo
            foreach ((array) ($message['attachments'] ?? []) as $att) {
                $a = Http::withToken($token)->acceptJson()->asJson()->post($draft . '/attachments', $att);
                if (!$a->successful()) return [false, 'attachment HTTP ' . $a->status()];
            }
            // 3b) anexos GRANDES (>3 MB) — via upload session (chunks)
            foreach ($largeAtts as $la) {
                [$uOk, $uErr] = self::uploadLargeAttachment($token, $draft, $la);
                if (!$uOk) return [false, $uErr];
            }

            // 4) envia o rascunho (fica nos Itens Enviados, dentro da conversa)
            $s = Http::withToken($token)->acceptJson()->withBody('{}', 'application/json')->post($draft . '/send');
            if (!$s->successful()) return [false, 'send HTTP ' . $s->status()];

            return [true, null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Envio via RASCUNHO (não-threaded) para suportar anexos grandes: cria a mensagem como
     * rascunho (com corpo + anexos pequenos), sobe os grandes por upload session e envia.
     * @param array<string,mixed> $message
     * @param array<int,array{name:string,mime:string,bytes:string}> $largeAtts
     * @return array{0: bool, 1: ?string}
     */
    private static function sendViaDraft(string $token, string $fromEmail, array $message, array $largeAtts): array
    {
        try {
            $base = sprintf('%s/users/%s', self::GRAPH_BASE, rawurlencode($fromEmail));
            $r = Http::withToken($token)->acceptJson()->asJson()->post($base . '/messages', $message);
            if (!$r->successful()) return [false, 'create draft HTTP ' . $r->status()];
            $draftId = (string) $r->json('id');
            if ($draftId === '') return [false, 'draft sem id'];
            $draft = $base . '/messages/' . rawurlencode($draftId);
            foreach ($largeAtts as $la) {
                [$uOk, $uErr] = self::uploadLargeAttachment($token, $draft, $la);
                if (!$uOk) return [false, $uErr];
            }
            $s = Http::withToken($token)->acceptJson()->withBody('{}', 'application/json')->post($draft . '/send');
            if (!$s->successful()) return [false, 'send HTTP ' . $s->status()];
            return [true, null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Sobe UM anexo grande (>3 MB, até 25 MB) num rascunho via createUploadSession + PUT em chunks
     * de 4 MB. A uploadUrl já vem pré-autenticada (sem Bearer). Último chunk devolve 201.
     * @param array{name:string,mime:string,bytes:string} $la
     * @return array{0: bool, 1: ?string}
     */
    private static function uploadLargeAttachment(string $token, string $draftUrl, array $la): array
    {
        $bytes = (string) $la['bytes'];
        $size  = strlen($bytes);
        $sess = Http::withToken($token)->acceptJson()->asJson()->post($draftUrl . '/attachments/createUploadSession', [
            'AttachmentItem' => [
                'attachmentType' => 'file',
                'name'           => (string) $la['name'],
                'size'           => $size,
                'contentType'    => (string) ($la['mime'] ?? 'application/octet-stream'),
            ],
        ]);
        if (!$sess->successful()) return [false, 'createUploadSession HTTP ' . $sess->status()];
        $uploadUrl = (string) $sess->json('uploadUrl');
        if ($uploadUrl === '') return [false, 'uploadSession sem uploadUrl'];

        $chunk = 4 * 1024 * 1024;
        $start = 0;
        while ($start < $size) {
            $end   = min($start + $chunk, $size) - 1;
            $slice = substr($bytes, $start, $end - $start + 1);
            $put = Http::withHeaders([
                'Content-Length' => (string) strlen($slice),
                'Content-Range'  => "bytes {$start}-{$end}/{$size}",
            ])->withBody($slice, 'application/octet-stream')->put($uploadUrl);
            if (!$put->successful()) return [false, 'upload chunk HTTP ' . $put->status()];
            $start = $end + 1;
        }
        return [true, null];
    }
}
