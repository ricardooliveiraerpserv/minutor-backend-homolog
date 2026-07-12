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
        // withFooter:false — a resposta JÁ termina com a assinatura formal do agente (logo ERPSERV
        // incluso). O rodapé genérico duplicaria o logo E, pior, era anexado DEPOIS do </html> do
        // nosso documento, quebrando a estrutura e fazendo o cliente ignorar color-scheme:light
        // (voltava a inverter p/ dark). Sem ele, o card claro é respeitado.
        [$ok, $err] = GraphMailSender::sendAs(
            (string) $from->email, [$to], $cc, self::subjectFor($ticket), $html, [], $attachments, false
        );
        if (!$ok) {
            Log::warning("HelpDesk: e-mail de resposta falhou ({$ticket->ticket_number} → {$to}): {$err}");
        }
        return [$ok, $err];
    }

    /**
     * Envia um e-mail AVULSO relacionado ao chamado, para destinatários livres, COMO a conta
     * do Help Desk (mesmo remetente/threading das respostas). Assunto e corpo são livres.
     *
     * @param array<int,string> $to
     * @param array<int,string> $cc
     * @return array{0: bool, 1: ?string}
     */
    public static function sendStandalone(HelpDeskTicket $ticket, array $to, array $cc, string $subject, string $html): array
    {
        $to = array_values(array_filter(array_map('trim', $to)));
        $cc = array_values(array_filter(array_map('trim', $cc)));
        if (empty($to)) return [false, 'skip:sem_destinatario'];
        if (trim(strip_tags($html)) === '') return [false, 'skip:corpo_vazio'];
        if (!GraphMailSender::enabled()) return [false, 'skip:graph_desligado'];

        $from = self::resolveFromAccount($ticket);
        if (!$from) return [false, 'skip:sem_conta_microsoft365'];

        [$body, $inline] = self::treatBody($html);
        [$ok, $err] = GraphMailSender::sendAs((string) $from->email, $to, $cc, $subject, $body, [], $inline, false);
        if (!$ok) {
            Log::warning("HelpDesk: e-mail avulso falhou ({$ticket->ticket_number}): {$err}");
        }
        return [$ok, $err];
    }

    /**
     * Trata o corpo da resposta p/ envio por e-mail:
     *  - extrai prints colados (<img src="data:image/...;base64,...">) e os converte em
     *    imagens INLINE (cid) — data: URIs são bloqueados pela maioria dos clientes;
     *  - troca imagens de caminho RELATIVO (ex.: o logo "/logo.png" do cabeçalho da Solução) pelo
     *    logo ERPSERV inline (cid) — src relativo não tem host no cliente de e-mail e vira "quebrado";
     *  - envelopa num CARD claro (fundo branco fixo) para o e-mail não sair "desconfigurado" quando
     *    o cliente está em dark mode (a auto-inversão bagunçava o corpo sem fundo).
     *
     * @return array{0: string, 1: array<int, array{name:string, mime:string, bytes:string, cid:string}>}
     */
    private static function treatBody(string $html): array
    {
        $inline = [];
        $i = 0;
        // 1) Prints colados (data:image) → imagem inline (cid).
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
                // PRESERVA a tag original (width/height/style) — troca só o src. Antes reconstruía
                // um <img> sem dimensões, o que estourava o tamanho dos ícones da assinatura (viravam
                // gigantes, ~90px nativo, ignorando o width="30"). Só o src vira cid:.
                return preg_replace('/\bsrc=["\']data:[^"\']*["\']/i', 'src="cid:' . $cid . '"', $m[0])
                    ?? ('<img src="cid:' . $cid . '" style="max-width:100%;height:auto">');
            },
            $html
        ) ?? $html;

        // 2) Imagens com src RELATIVO (não cid:/http(s):/data:) → logo ERPSERV inline (cid).
        //    É o caso do "/logo.png" do cabeçalho do Detalhamento da Solução, que quebrava no e-mail.
        $usedLogo = false;
        $html = preg_replace_callback(
            '/<img\b([^>]*?)\bsrc=["\'](?!cid:|https?:|data:)[^"\']*["\']([^>]*)>/i',
            function ($m) use (&$usedLogo) {
                $usedLogo = true;
                return '<img' . $m[1] . ' src="cid:' . \App\Services\HelpDeskMailFooter::LOGO_CID . '"' . $m[2] . '>';
            },
            $html
        ) ?? $html;
        if ($usedLogo && ($logo = \App\Services\HelpDeskMailFooter::inlineLogo())) {
            $inline[] = $logo;
        }

        // 2b) background-image:url('data:image/...') → cid. É o caso da FOTO redonda da assinatura,
        //     que virou background de <div> (p/ o Apple Mail não colocar borda). Exchange preserva
        //     background-image:url(cid:), mas descarta data: em CSS — então convertemos aqui.
        $html = preg_replace_callback(
            '/background-image:\s*url\(\s*([\'"]?)data:(image\/[a-zA-Z0-9.+-]+);base64,([^\'")]+)\1\s*\)/i',
            function ($m) use (&$inline, &$i) {
                $bytes = base64_decode($m[3], true);
                if ($bytes === false || $bytes === '') return $m[0];
                $i++;
                $mime = strtolower($m[2]);
                $ext  = match ($mime) {
                    'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
                    'image/gif' => 'gif', 'image/webp' => 'webp', default => 'img',
                };
                $cid = "hdbg{$i}@minutor";
                $inline[] = ['name' => "bg{$i}.{$ext}", 'mime' => $mime, 'bytes' => $bytes, 'cid' => $cid];
                return "background-image:url('cid:{$cid}')";
            },
            $html
        ) ?? $html;

        // 3) Envelopa SEM forçar cor de fundo: deixamos o cliente renderizar no modo dele
        //    (claro/escuro). Forçar o branco (background-image) deixava os ícones roxos opacos no
        //    dark mode — o usuário preferiu o fundo natural, que mantém as cores vivas nos dois modos.
        $wrapped = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5;max-width:760px">'
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

    /**
     * Aviso ao cliente quando ele responde por e-mail um chamado ENCERRADO: informa que o chamado
     * anterior está fechado, dá o link do histórico e o número do NOVO chamado criado. O assunto usa
     * o token do NOVO chamado → futuras respostas passam a threadar nele.
     *
     * @return array{0: bool, 1: ?string}
     */
    public static function sendClosedTicketNotice(HelpDeskEmailAccount $acc, HelpDeskTicket $old, HelpDeskTicket $new, ?string $to): array
    {
        if (!$to) return [false, 'skip:sem_destinatario'];
        if (!GraphMailSender::enabled()) return [false, 'skip:graph_desligado'];

        // O link do E-MAIL aponta sempre para o chamado ATUAL (o novo). O link do chamado ANTERIOR
        // fica só dentro do novo chamado (na plataforma), na descrição — não vai no e-mail.
        $link  = rtrim((string) config('app.frontend_url'), '/') . '/help-desk/portal?ticket=' . $new->id;
        $novo  = e($new->ticket_number);
        $ant   = e($old->ticket_number);
        $title = 'Demos continuidade ao seu atendimento';
        $msg = '<p style="margin:0 0 12px">Olá,</p>'
            . '<p style="margin:0 0 12px">Para dar continuidade ao seu atendimento, abrimos um novo chamado: <b>' . $novo . '</b>.</p>'
            . '<p style="margin:0 0 12px">Essa ação foi realizada para manter um histórico organizado e garantir que o acompanhamento da sua solicitação continue normalmente.</p>'
            . '<p style="margin:0 0 12px">O chamado anterior (<b>' . $ant . '</b>) foi encerrado, mas todo o contexto permanece registrado e poderá ser consultado a qualquer momento.</p>'
            . '<p style="margin:0 0 16px">A partir de agora, basta responder este e-mail para continuar o atendimento no chamado <b>' . $novo . '</b>.</p>'
            . '<p style="margin:0 0 8px">🔗 <a href="' . e($link) . '" style="color:#1d4ed8;text-decoration:underline">Acompanhar o chamado ' . $novo . '</a></p>';

        // Faixa roxa (header): sempre nº do chamado ATUAL + título.
        $headerLine = 'Chamado ' . $new->ticket_number . ' — ' . $new->subject;
        $html = HelpDeskMailComposer::composeSimple($title, $msg, null, $headerLine);
        [$html, $imgAtts] = HelpDeskMailComposer::inlineImages($html);
        $inline = array_merge(HelpDeskMailComposer::inlineAssetsSimple(), $imgAtts);
        // Assunto com o token do NOVO chamado → o cliente responde e cai no chamado novo.
        $subject = self::subjectFor($new);
        [$ok, $err] = GraphMailSender::sendAs((string) $acc->email, [$to], [], $subject, $html, [], $inline, false);
        if (!$ok) {
            Log::warning("HelpDesk: aviso de chamado encerrado falhou ({$old->ticket_number}→{$to}): {$err}");
        }
        return [$ok, $err];
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
