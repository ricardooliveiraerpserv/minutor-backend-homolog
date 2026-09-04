<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Envio de e-mail via Microsoft Graph (app-only / client-credentials), AS the
 * caixa do remetente logado (Send As por permissão de aplicação Mail.Send +
 * Application Access Policy). SEM SMTP, SEM app password.
 *
 * DORMENTE por padrão: sem as 3 variáveis em config('services.graph')
 * enabled() retorna false e os controllers caem no fluxo SMTP/SenderMailer atual.
 *
 * Usa apenas o HTTP client nativo do Laravel (Illuminate\Support\Facades\Http) —
 * NENHUMA dependência composer adicional.
 */
class GraphMailer
{
    private const TOKEN_URL  = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    /** Anexos inline (contentBytes) só valem até ~3 MB no total num único sendMail. */
    public const MAX_INLINE_ATTACHMENTS_BYTES = 3 * 1024 * 1024;

    /**
     * Teto TOTAL de anexos quando enviado via RASCUNHO (draft + anexos individuais /
     * upload session). Sobe bem além do sendMail inline (~4 MB de request). 25 MB é o teto
     * prático/padrão de tamanho de mensagem da caixa (dá pra subir se a org permitir mais).
     */
    public const MAX_TOTAL_ATTACHMENTS_BYTES = 25 * 1024 * 1024;

    /** true só quando as 3 credenciais estão preenchidas. */
    public static function enabled(): bool
    {
        $c = config('services.graph');

        return !empty($c['tenant_id'])
            && !empty($c['client_id'])
            && !empty($c['client_secret']);
    }

    /**
     * Token client-credentials (cacheado ~50min; tokens duram ~60min).
     *
     * @throws \RuntimeException em qualquer falha de obtenção.
     */
    protected static function token(): string
    {
        $c = config('services.graph');

        return Cache::remember('graph_mail_token', now()->addMinutes(50), function () use ($c) {
            $resp = Http::asForm()->post(sprintf(self::TOKEN_URL, $c['tenant_id']), [
                'grant_type'    => 'client_credentials',
                'client_id'     => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'scope'         => 'https://graph.microsoft.com/.default',
            ]);

            if (!$resp->successful()) {
                throw new \RuntimeException(
                    'Graph: falha ao obter token (HTTP ' . $resp->status() . '): ' . $resp->body()
                );
            }

            $token = $resp->json('access_token');
            if (empty($token)) {
                throw new \RuntimeException('Graph: token de acesso vazio na resposta do Azure AD.');
            }

            return $token;
        });
    }

    /**
     * Envia um e-mail HTML COMO a caixa $fromEmail.
     *
     * @param array<int, string> $to               e-mails (filtra vazios)
     * @param array<int, string> $cc               e-mails (filtra vazios)
     * @param array<int, string> $attachmentPaths  caminhos locais (PDF/XLSX/etc)
     *
     * @throws \RuntimeException em resposta não-2xx do Graph (com o corpo) ou anexos > ~3 MB.
     */
    public static function sendAs(
        string $fromEmail,
        array $to,
        array $cc,
        string $subject,
        string $htmlBody,
        array $attachmentPaths = []
    ): void {
        $token = self::token();

        // Anexos > ~3 MB no total não cabem no sendMail inline (teto de ~4 MB de request do
        // Graph). Nesse caso, envia via RASCUNHO: cria o draft, anexa cada arquivo num request
        // separado (upload session pros individuais > 3 MB) e envia. Sobe o teto pro tamanho
        // máx. de mensagem da caixa (~25 MB).
        $paths = array_values(array_filter(array_map('trim', $attachmentPaths), fn ($p) => $p !== '' && is_file($p)));
        $total = array_sum(array_map(fn ($p) => (int) filesize($p), $paths));
        if ($total > self::MAX_INLINE_ATTACHMENTS_BYTES) {
            if ($total > self::MAX_TOTAL_ATTACHMENTS_BYTES) {
                throw new \RuntimeException('Graph: anexos excedem ' . (int) round(self::MAX_TOTAL_ATTACHMENTS_BYTES / 1048576) . ' MB (teto da caixa).');
            }
            self::sendViaDraft($token, $fromEmail, $to, $cc, [], $subject, $htmlBody, $paths);
            return;
        }

        $message = self::buildMessage($subject, $htmlBody, $to, $cc, $attachmentPaths);
        $url   = sprintf('%s/users/%s/sendMail', self::GRAPH_BASE, rawurlencode($fromEmail));

        $resp = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->post($url, [
                'message'         => $message,
                'saveToSentItems' => true,
            ]);

        if (!$resp->successful()) {
            throw new \RuntimeException(
                'Graph sendMail falhou (HTTP ' . $resp->status() . '): ' . $resp->body()
            );
        }
    }

    /**
     * Envio via RASCUNHO (draft) para anexos grandes: cria a mensagem sem anexos, anexa cada
     * arquivo num request separado (upload session pros > 3 MB) e envia. Contorna o teto de
     * ~4 MB de request do sendMail inline.
     *
     * Reusável pelos dois envios (sistema e Help Desk) — cada um passa o SEU token.
     * $byteAttachments = anexos em memória (cid = imagem inline no corpo).
     *
     * @param array<int,string> $to
     * @param array<int,string> $cc
     * @param array<int,string> $bcc
     * @param array<int,string> $paths
     * @param array<int,array{name:string,mime?:string,bytes:string,cid?:string}> $byteAttachments
     */
    public static function sendViaDraft(string $token, string $fromEmail, array $to, array $cc, array $bcc, string $subject, string $htmlBody, array $paths, array $byteAttachments = []): void
    {
        $base = sprintf('%s/users/%s', self::GRAPH_BASE, rawurlencode($fromEmail));

        // 1) Cria o rascunho (sem anexos de arquivo).
        $draft = [
            'subject'      => $subject,
            'body'         => ['contentType' => 'HTML', 'content' => $htmlBody],
            'toRecipients' => self::recipients($to),
            'ccRecipients' => self::recipients($cc),
        ];
        $bccR = self::recipients($bcc);
        if (!empty($bccR)) $draft['bccRecipients'] = $bccR;
        $create = Http::withToken($token)->acceptJson()->asJson()->post($base . '/messages', $draft);
        if (!$create->successful()) {
            throw new \RuntimeException('Graph criar rascunho falhou (HTTP ' . $create->status() . '): ' . $create->body());
        }
        $msgId = (string) $create->json('id');
        if ($msgId === '') {
            throw new \RuntimeException('Graph: rascunho criado sem id.');
        }

        try {
            // 2) Anexa cada arquivo — request separado (sem o teto de ~4 MB do sendMail).
            foreach ($paths as $path) {
                $size = (int) filesize($path);
                if ($size <= self::MAX_INLINE_ATTACHMENTS_BYTES) {
                    $r = Http::withToken($token)->acceptJson()->asJson()->post($base . '/messages/' . $msgId . '/attachments', [
                        '@odata.type'  => '#microsoft.graph.fileAttachment',
                        'name'         => basename($path),
                        'contentType'  => self::contentTypeFor(basename($path)),
                        'contentBytes' => base64_encode((string) file_get_contents($path)),
                    ]);
                    if (!$r->successful()) {
                        throw new \RuntimeException('Graph anexar falhou (HTTP ' . $r->status() . '): ' . $r->body());
                    }
                } else {
                    self::uploadLargeAttachment($token, $base, $msgId, $path, $size);
                }
            }

            // 2b) Anexos em memória (bytes) — incluindo imagens inline (cid).
            foreach ($byteAttachments as $a) {
                $bytes = (string) ($a['bytes'] ?? '');
                if ($bytes === '') continue;
                $entry = [
                    '@odata.type'  => '#microsoft.graph.fileAttachment',
                    'name'         => (string) ($a['name'] ?? 'anexo'),
                    'contentType'  => (string) ($a['mime'] ?? 'application/octet-stream'),
                    'contentBytes' => base64_encode($bytes),
                ];
                if (!empty($a['cid'])) { $entry['contentId'] = (string) $a['cid']; $entry['isInline'] = true; }
                $r = Http::withToken($token)->acceptJson()->asJson()->post($base . '/messages/' . $msgId . '/attachments', $entry);
                if (!$r->successful()) {
                    throw new \RuntimeException('Graph anexar (memória) falhou (HTTP ' . $r->status() . '): ' . $r->body());
                }
            }

            // 3) Envia o rascunho.
            $send = Http::withToken($token)->acceptJson()->post($base . '/messages/' . $msgId . '/send');
            if (!$send->successful()) {
                throw new \RuntimeException('Graph enviar rascunho falhou (HTTP ' . $send->status() . '): ' . $send->body());
            }
        } catch (\Throwable $e) {
            // Não deixa rascunho órfão na caixa.
            try { Http::withToken($token)->delete($base . '/messages/' . $msgId); } catch (\Throwable $ignored) {}
            throw $e;
        }
    }

    /** Anexo individual > 3 MB: upload session em chunks (múltiplos de 320 KiB, exceto o último). */
    private static function uploadLargeAttachment(string $token, string $base, string $msgId, string $path, int $size): void
    {
        $session = Http::withToken($token)->acceptJson()->asJson()->post($base . '/messages/' . $msgId . '/attachments/createUploadSession', [
            'AttachmentItem' => [
                'attachmentType' => 'file',
                'name'           => basename($path),
                'size'           => $size,
                'contentType'    => self::contentTypeFor(basename($path)),
            ],
        ]);
        if (!$session->successful()) {
            throw new \RuntimeException('Graph upload session falhou (HTTP ' . $session->status() . '): ' . $session->body());
        }
        $uploadUrl = (string) $session->json('uploadUrl');
        if ($uploadUrl === '') {
            throw new \RuntimeException('Graph: upload session sem uploadUrl.');
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Graph: não consegui abrir anexo {$path}.");
        }
        $chunk  = 10 * 320 * 1024; // 3,125 MB — múltiplo de 320 KiB (requisito do Graph)
        $offset = 0;
        try {
            while (!feof($fh)) {
                $data = fread($fh, $chunk);
                $len  = strlen((string) $data);
                if ($len === 0) break;
                $end = $offset + $len - 1;
                // O uploadUrl é pré-autenticado — NÃO enviar o bearer token. Content-Length é
                // calculado pelo cliente HTTP a partir do corpo.
                $r = Http::withHeaders([
                    'Content-Range' => "bytes {$offset}-{$end}/{$size}",
                ])->withBody((string) $data, 'application/octet-stream')->put($uploadUrl);
                if (!$r->successful()) {
                    throw new \RuntimeException('Graph upload chunk falhou (HTTP ' . $r->status() . '): ' . $r->body());
                }
                $offset += $len;
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * Monta o array `message` do payload sendMail. Static/puro para ser testável
     * sem chamadas de rede.
     *
     * @param array<int, string> $to
     * @param array<int, string> $cc
     * @param array<int, string> $attachmentPaths
     * @return array<string, mixed>
     *
     * @throws \RuntimeException se o total de anexos exceder ~3 MB (precisa upload session).
     */
    public static function buildMessage(
        string $subject,
        string $htmlBody,
        array $to,
        array $cc,
        array $attachmentPaths = []
    ): array {
        $message = [
            'subject'      => $subject,
            'body'         => [
                'contentType' => 'HTML',
                'content'     => $htmlBody,
            ],
            'toRecipients' => self::recipients($to),
            'ccRecipients' => self::recipients($cc),
        ];

        $attachments = self::buildAttachments($attachmentPaths);
        if (!empty($attachments)) {
            $message['attachments'] = $attachments;
        }

        return $message;
    }

    /**
     * @param array<int, string> $emails
     * @return array<int, array{emailAddress: array{address: string}}>
     */
    private static function recipients(array $emails): array
    {
        $clean = array_values(array_filter(array_map('trim', $emails), fn ($e) => $e !== ''));

        return array_map(fn ($email) => ['emailAddress' => ['address' => $email]], $clean);
    }

    /**
     * @param array<int, string> $attachmentPaths
     * @return array<int, array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    private static function buildAttachments(array $attachmentPaths): array
    {
        $paths = array_values(array_filter(array_map('trim', $attachmentPaths), fn ($p) => $p !== ''));
        if (empty($paths)) {
            return [];
        }

        $attachments = [];
        $totalBytes  = 0;

        foreach ($paths as $path) {
            if (!is_file($path)) {
                throw new \RuntimeException("Graph: anexo não encontrado: {$path}");
            }

            $bytes = (string) file_get_contents($path);
            $totalBytes += strlen($bytes);

            if ($totalBytes > self::MAX_INLINE_ATTACHMENTS_BYTES) {
                throw new \RuntimeException(
                    'Graph: anexos excedem ~3 MB no total; anexos grandes exigem upload session '
                    . '(createUploadSession), ainda não implementado. Fechamento atual < 1 MB.'
                );
            }

            $name = basename($path);
            $attachments[] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $name,
                'contentType'  => self::contentTypeFor($name),
                'contentBytes' => base64_encode($bytes),
            ];
        }

        return $attachments;
    }

    /** Infere o Content-Type pela extensão (pdf/xlsx; senão octet-stream). */
    private static function contentTypeFor(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf'  => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            default => 'application/octet-stream',
        };
    }
}
