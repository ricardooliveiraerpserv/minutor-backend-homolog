<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Leitura de caixas via Microsoft Graph (app-only / client-credentials), permissão de
 * aplicação Mail.Read. É o caminho correto para "conectar via Exchange/Office 365": a
 * Microsoft desativou o Basic Auth (IMAP/POP/SMTP com senha) no Exchange Online, então
 * caixas O365 só são lidas via OAuth2/Graph.
 *
 * App-only = consentimento org-wide; lê qualquer caixa do tenant SEM o pop-up de escolher
 * conta (o "Reautorizar com Outlook" delegado só é necessário para caixas FORA do tenant).
 *
 * DORMENTE por padrão: sem as 3 credenciais em config('services.graph_reader'),
 * enabled() retorna false. Usa só o HTTP client do Laravel — zero dependência composer.
 */
class GraphMailReader
{
    private const TOKEN_URL  = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    /** true só quando as 3 credenciais do leitor estão preenchidas. */
    public static function enabled(): bool
    {
        $c = config('services.graph_reader');

        return !empty($c['tenant_id'])
            && !empty($c['client_id'])
            && !empty($c['client_secret']);
    }

    /**
     * Token client-credentials do leitor (cache próprio, ~50min).
     *
     * @throws \RuntimeException em qualquer falha de obtenção.
     */
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
                throw new \RuntimeException(
                    'Graph: falha ao obter token de leitura (HTTP ' . $resp->status() . '): ' . $resp->body()
                );
            }

            $token = $resp->json('access_token');
            if (empty($token)) {
                throw new \RuntimeException('Graph: token de leitura vazio na resposta do Azure AD.');
            }

            return $token;
        });
    }

    /**
     * Testa a conexão com a caixa: lê a pasta Inbox via Graph.
     * Retorna [ok, erro|null, totalNaInbox|null].
     *
     * @return array{0: bool, 1: ?string, 2: ?int}
     */
    public static function inboxStatus(string $mailbox): array
    {
        $mailbox = trim($mailbox);
        if ($mailbox === '') {
            return [false, 'E-mail da caixa não informado.', null];
        }
        if (!self::enabled()) {
            return [false, 'Microsoft Graph (leitura) não configurado no servidor — preencha GRAPH_TENANT_ID/CLIENT_ID/CLIENT_SECRET.', null];
        }

        try {
            $token = self::token();
        } catch (\Throwable $e) {
            return [false, $e->getMessage(), null];
        }

        $url  = sprintf('%s/users/%s/mailFolders/Inbox', self::GRAPH_BASE, rawurlencode($mailbox));
        $resp = Http::withToken($token)->acceptJson()
            ->get($url, ['$select' => 'totalItemCount,unreadItemCount']);

        if ($resp->successful()) {
            return [true, null, (int) ($resp->json('totalItemCount') ?? 0)];
        }

        // Mensagens de erro mais úteis para os casos comuns do Graph.
        $msg = $resp->json('error.message') ?: ('HTTP ' . $resp->status());
        if ($resp->status() === 404) {
            $msg = "Caixa '{$mailbox}' não encontrada no tenant (verifique o endereço).";
        } elseif ($resp->status() === 403) {
            $msg = "Sem permissão para ler '{$mailbox}'. Confirme Mail.Read de aplicativo e a Application Access Policy no Azure. ({$msg})";
        }

        return [false, $msg, null];
    }

    /**
     * Lista mensagens recentes da Inbox (para a futura ingestão → chamado).
     * Retorna o array `value` do Graph (vazio em falha).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentMessages(string $mailbox, int $top = 10): array
    {
        if (!self::enabled() || trim($mailbox) === '') {
            return [];
        }

        try {
            $token = self::token();
        } catch (\Throwable $e) {
            return [];
        }

        $url  = sprintf('%s/users/%s/mailFolders/Inbox/messages', self::GRAPH_BASE, rawurlencode(trim($mailbox)));
        $resp = Http::withToken($token)->acceptJson()->get($url, [
            '$top'     => max(1, min($top, 50)),
            '$orderby' => 'receivedDateTime desc',
            '$select'  => 'id,subject,from,receivedDateTime,bodyPreview,body,isRead,hasAttachments',
        ]);

        return $resp->successful() ? (array) ($resp->json('value') ?? []) : [];
    }

    /**
     * Baixa os anexos (fileAttachment) de uma mensagem. Retorna lista de
     * ['name','mime','bytes' (binário já decodificado),'inline' (bool)].
     * Ignora itemAttachment/referenceAttachment (sem contentBytes).
     *
     * @return array<int, array{name:string, mime:string, bytes:string, inline:bool, contentId:string}>
     */
    public static function messageAttachments(string $mailbox, string $messageId): array
    {
        if (!self::enabled() || trim($mailbox) === '' || trim($messageId) === '') return [];

        try {
            $token = self::token();
        } catch (\Throwable $e) {
            return [];
        }

        $url  = sprintf('%s/users/%s/messages/%s/attachments', self::GRAPH_BASE, rawurlencode(trim($mailbox)), rawurlencode($messageId));
        $resp = Http::withToken($token)->acceptJson()->get($url);
        if (!$resp->successful()) return [];

        $out = [];
        foreach ((array) ($resp->json('value') ?? []) as $att) {
            if (($att['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment') continue;
            $raw = base64_decode((string) ($att['contentBytes'] ?? ''), true);
            if ($raw === false || $raw === '') continue;
            $out[] = [
                'name'      => (string) ($att['name'] ?? 'anexo'),
                'mime'      => (string) ($att['contentType'] ?? 'application/octet-stream'),
                'bytes'     => $raw,
                'inline'    => (bool) ($att['isInline'] ?? false),
                'contentId' => trim((string) ($att['contentId'] ?? ''), " <>"),
            ];
        }
        return $out;
    }
}
