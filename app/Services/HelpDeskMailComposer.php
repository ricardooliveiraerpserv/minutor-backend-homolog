<?php

namespace App\Services;

use App\Models\HelpDeskCommTemplate;
use App\Models\HelpDeskTicket;

/**
 * Monta o e-mail INSTITUCIONAL do Help Desk a partir de (mensagem + blocos), usando o
 * Template Institucional global. O admin informa só assunto+mensagem+blocos; o layout
 * (logo, cabeçalho, assinatura, rodapé, cores) é montado aqui. NÃO altera o motor de gatilhos.
 *
 * Compat: send_email com `body` legado continua sendo renderizado direto (sem este composer).
 */
class HelpDeskMailComposer
{
    /** Blocos disponíveis p/ inserir (chave => rótulo). */
    public const BLOCKS = [
        'ticket_data'  => 'Dados do chamado',
        'summary'      => 'Resumo do chamado',
        'last_public'  => 'Última interação pública',
        'customer'     => 'Dados do cliente',
        'assignee'     => 'Dados do responsável',
        'sla'          => 'SLA',
        'button'       => 'Botão "Abrir chamado"',
    ];

    /**
     * Logo via cid (e-mail) ou data: (prévia). $audience define a quem é a saudação:
     * 'responsavel' → cumprimenta o agente; senão → o solicitante/cliente (NÃO o agente).
     */
    public static function compose(string $message, array $blocks, HelpDeskTicket $ticket, ?string $logoSrc = null, string $audience = 'cliente'): string
    {
        $tpl   = HelpDeskCommTemplate::current();
        $logo  = $logoSrc ?? ('cid:' . HelpDeskMailFooter::LOGO_CID);
        $color = $tpl->primary_color ?: '#7c3aed';
        $font  = $tpl->font ?: 'Arial, Helvetica, sans-serif';
        $greetName = $audience === 'responsavel'
            ? trim((string) optional($ticket->assignee)->name)
            : trim((string) ($ticket->solicitanteName() ?? ''));
        $greeting = $greetName !== '' ? 'Olá ' . e($greetName) . ',' : 'Olá,';
        $msgHtml  = nl2br(e(HelpDeskTriggerEngine::render($message, $ticket)));

        $blocksHtml = '';
        foreach ($blocks as $b) {
            $blocksHtml .= self::renderBlock((string) $b, $ticket, $color);
        }

        $minutor = $tpl->show_minutor
            ? '<div style="font-size:11px;color:#9ca3af;margin-top:4px">Mensagem automática · enviada via <span style="color:#6b7280;font-weight:600">Minutor</span></div>'
            : '';

        return ''
        . '<div style="margin:0;padding:0;background:#f3f4f6">'
        .   '<div style="max-width:600px;margin:0 auto;background:#ffffff;font-family:' . $font . ';color:#1f2937">'
        .     '<div style="height:6px;background:' . $color . '"></div>'
        .     '<div style="padding:20px 24px;border-bottom:1px solid #eef0f3">'
        .       '<img src="' . $logo . '" alt="' . e($tpl->company_name) . '" style="height:40px;width:auto;display:block;border:0" />'
        .     '</div>'
        .     '<div style="padding:22px 24px">'
        .       '<p style="margin:0 0 12px;font-size:15px">' . $greeting . '</p>'
        .       '<div style="font-size:14px;line-height:1.6">' . $msgHtml . '</div>'
        .       $blocksHtml
        .       '<p style="margin:22px 0 0;font-size:14px;color:#374151">' . e($tpl->signature) . '<br><b>' . e($tpl->company_name) . '</b></p>'
        .     '</div>'
        .     '<div style="padding:14px 24px;background:#fafafa;border-top:1px solid #eef0f3">'
        .       '<div style="font-size:12px;color:#6b7280">' . e($tpl->footer_text) . '</div>'
        .       $minutor
        .     '</div>'
        .   '</div>'
        . '</div>';
    }

    /**
     * Layout institucional GENÉRICO (sem chamado): título + mensagem. Usado p/ notificações.
     * Cabeçalho = FAIXA da cor da marca + LOGO BRANCO (sobrevive ao dark mode: cor de marca não
     * é invertida pelos clientes e o logo é imagem). Corpo claro com tema ESCURO intencional via
     * media query (clientes que honram). Default do logo = cid do logo branco.
     */
    public static function composeSimple(string $title, string $messageHtml, ?string $logoSrc = null): string
    {
        $tpl   = HelpDeskCommTemplate::current();
        $logo  = $logoSrc ?? ('cid:' . HelpDeskMailFooter::LOGO_WHITE_CID);
        $color = $tpl->primary_color ?: '#7c3aed';
        $font  = $tpl->font ?: 'Arial, Helvetica, sans-serif';
        $minutor = $tpl->show_minutor
            ? '<div class="hde-foot" style="font-size:11px;color:#9ca3af;margin-top:4px">Mensagem automática · enviada via <span style="color:#6b7280;font-weight:600">Minutor</span></div>' : '';

        $inner = '<div class="hde-outer" style="margin:0;padding:0;background:#f3f4f6">'
            . '<div class="hde-card" style="max-width:600px;margin:0 auto;background:#ffffff;font-family:' . $font . ';color:#1f2937;border-radius:10px;overflow:hidden;border:1px solid #e9ebef">'
            // Cabeçalho colorido (marca) + logo branco — legível em claro e escuro.
            .   '<div class="hde-head" style="background:' . $color . ';padding:18px 24px" bgcolor="' . $color . '"><img src="' . $logo . '" alt="' . e($tpl->company_name) . '" style="height:30px;width:auto;display:block;border:0" /></div>'
            .   '<div style="padding:22px 24px">'
            .     '<h2 class="hde-title" style="margin:0 0 12px;font-size:18px;color:#111827">' . e($title) . '</h2>'
            .     '<div class="hde-text" style="font-size:14px;line-height:1.6;color:#1f2937">' . $messageHtml . '</div>'
            .   '</div>'
            .   '<div class="hde-footwrap" style="padding:14px 24px;background:#fafafa;border-top:1px solid #eef0f3"><div class="hde-foot" style="font-size:12px;color:#6b7280">' . e($tpl->footer_text) . '</div>' . $minutor . '</div>'
            . '</div></div>';

        return self::wrapDocument($inner, $color);
    }

    /** Anexo inline do logo BRANCO (cabeçalho do e-mail de notificação). */
    public static function inlineAssetsSimple(): array
    {
        $logo = HelpDeskMailFooter::inlineWhiteLogo();
        return $logo ? [$logo] : [];
    }

    /**
     * Documento completo. Mantemos o corpo CLARO (card branco) e o cabeçalho com a FAIXA de marca
     * + logo branco — que funciona nos dois modos. NÃO forçamos um tema escuro via media query: ela
     * segue o OS (prefers-color-scheme), não o tema do cliente de e-mail; com OS escuro + cliente
     * claro o card escuro vazava indevidamente. Em clientes escuros, a auto-inversão deixa o card
     * escuro legível e a faixa roxa + logo branco continuam nítidos.
     */
    private static function wrapDocument(string $bodyHtml, string $brand = '#7c3aed'): string
    {
        return '<!doctype html><html lang="pt-BR"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark">'
            . '<meta name="supported-color-schemes" content="light dark">'
            . '<style>body{margin:0;padding:0;background:#f3f4f6}</style>'
            . '</head><body>' . $bodyHtml . '</body></html>';
    }

    /** Anexo inline do logo (cid) p/ o envio. */
    public static function inlineAssets(): array
    {
        $logo = HelpDeskMailFooter::inlineLogo();
        return $logo ? [$logo] : [];
    }

    // ── Blocos ────────────────────────────────────────────────────────────────
    private static function renderBlock(string $block, HelpDeskTicket $ticket, string $color): string
    {
        return match ($block) {
            'ticket_data' => self::blockTable('Dados do chamado', [
                'Número'      => (string) $ticket->ticket_number,
                'Cliente'     => (string) optional($ticket->customer)->name,
                'Solicitante' => trim(((string) $ticket->solicitanteName()) . ($ticket->solicitanteEmail() ? ' · ' . $ticket->solicitanteEmail() : '')),
                'Status'      => (string) optional($ticket->status)->label,
                'Prioridade'  => ucfirst((string) $ticket->priority),
                'Categoria'   => (string) optional($ticket->category)->name,
                'Serviço'     => (string) optional($ticket->service)->name,
                'Responsável' => (string) optional($ticket->assignee)->name,
            ]),
            'summary' => self::blockBox('Resumo', self::richHtml((string) $ticket->description)),
            'last_public' => self::blockBox('Última interação', self::richHtml((string) optional(
                $ticket->comments()->where('visibility', 'customer')->orderByDesc('id')->first()
            )->body)),
            'customer' => self::blockTable('Cliente', [
                'Empresa' => (string) optional($ticket->customer)->name,
                'Contato' => (string) $ticket->solicitanteName(),
                'E-mail'  => (string) $ticket->solicitanteEmail(),
            ]),
            'assignee' => self::blockTable('Responsável', [
                'Agente' => (string) optional($ticket->assignee)->name,
                'E-mail' => (string) optional($ticket->assignee)->email,
                'Equipe' => (string) optional($ticket->team)->name,
            ]),
            'sla' => self::blockTable('SLA', [
                '1ª resposta' => $ticket->first_response_due_at ? $ticket->first_response_due_at->format('d/m/Y H:i') : '—',
                'Resolução'   => $ticket->resolution_due_at ? $ticket->resolution_due_at->format('d/m/Y H:i') : '—',
                'Situação'    => $ticket->resolution_breached ? 'SLA vencido' : 'Dentro do prazo',
            ]),
            'button' => self::blockButton($ticket, $color),
            default  => '',
        };
    }

    private static function blockTitle(string $t): string
    {
        return '<div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;margin:20px 0 8px">' . e($t) . '</div>';
    }

    /** @param array<string,string> $rows */
    private static function blockTable(string $title, array $rows): string
    {
        $tr = '';
        foreach ($rows as $k => $v) {
            if (trim((string) $v) === '') continue;
            $tr .= '<tr>'
                . '<td style="padding:6px 10px;background:#f9fafb;border:1px solid #eef0f3;font-size:13px;color:#6b7280;white-space:nowrap">' . e($k) . '</td>'
                . '<td style="padding:6px 10px;border:1px solid #eef0f3;font-size:13px;color:#111827">' . e($v) . '</td>'
                . '</tr>';
        }
        if ($tr === '') return '';
        return self::blockTitle($title) . '<table style="border-collapse:collapse;width:100%">' . $tr . '</table>';
    }

    private static function blockBox(string $title, string $html): string
    {
        return self::blockTitle($title)
            . '<div style="padding:10px 12px;background:#f9fafb;border:1px solid #eef0f3;border-radius:8px;font-size:13px;line-height:1.5;color:#374151">' . $html . '</div>';
    }

    private static function blockButton(HelpDeskTicket $ticket, string $color): string
    {
        $tpl = HelpDeskCommTemplate::current();
        $url = HelpDeskTriggerEngine::render('{ticket.url}', $ticket);
        return '<div style="margin:22px 0 4px">'
            . '<a href="' . e($url) . '" style="display:inline-block;background:' . $color . ';color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:10px 22px;border-radius:8px">' . e($tpl->button_label) . '</a>'
            . '</div>';
    }

    private static function plain(string $html): string
    {
        $html = preg_replace('/<\s*(br|\/p|\/div|\/li)\s*\/?>/i', "\n", $html) ?? $html;
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Conteúdo FIEL ao chamado (preserva formatação, imagens e assinatura). Remove só o
     * perigoso (script/iframe). Mantém as imagens inline (data:) — viram cid no envio.
     */
    private static function richHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '—';
        $html = preg_replace('/<\s*(script|iframe|object|embed|style)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? $html;
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html) ?? $html; // on* handlers
        return '<div style="max-width:100%;overflow-x:auto">' . $html . '</div>';
    }

    /**
     * Converte imagens inline `data:` do HTML em anexos INLINE (cid) — pro e-mail renderizar
     * a imagem/assinatura do chamado (clientes bloqueiam data:). Retorna [html, anexos].
     *
     * @return array{0:string, 1:array<int,array{name:string,mime:string,bytes:string,cid:string}>}
     */
    public static function inlineImages(string $html): array
    {
        $atts = []; $i = 0;
        $html = preg_replace_callback(
            '/<img\b[^>]*\bsrc=["\']data:(image\/[a-zA-Z0-9.+-]+);base64,([^"\']+)["\'][^>]*>/i',
            function ($m) use (&$atts, &$i) {
                $bytes = base64_decode($m[2], true);
                if ($bytes === false || $bytes === '') return '';
                $i++;
                $ext = match (strtolower($m[1])) { 'image/png' => 'png', 'image/jpeg', 'image/jpg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', default => 'img' };
                $cid = "ticketimg{$i}@minutor";
                $atts[] = ['name' => "img{$i}.{$ext}", 'mime' => strtolower($m[1]), 'bytes' => $bytes, 'cid' => $cid];
                // preserva o resto da tag <img> trocando só o src
                $tag = preg_replace('/\bsrc=["\']data:[^"\']*["\']/i', 'src="cid:' . $cid . '"', $m[0]) ?? ('<img src="cid:' . $cid . '" style="max-width:100%">');
                return $tag;
            },
            $html
        ) ?? $html;
        // background-image:url('data:image/...') → cid. É o caso da FOTO redonda da assinatura,
        // que virou background de <span> (p/ o Apple Mail não pôr borda no dark mode). O Exchange
        // descarta data: em CSS, então convertemos aqui como já fazemos com <img>.
        $html = preg_replace_callback(
            '/background-image:\s*url\(\s*([\'"]?)data:(image\/[a-zA-Z0-9.+-]+);base64,([^\'")]+)\1\s*\)/i',
            function ($m) use (&$atts, &$i) {
                $bytes = base64_decode($m[3], true);
                if ($bytes === false || $bytes === '') return $m[0];
                $i++;
                $ext = match (strtolower($m[2])) { 'image/png' => 'png', 'image/jpeg', 'image/jpg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', default => 'img' };
                $cid = "ticketbg{$i}@minutor";
                $atts[] = ['name' => "bg{$i}.{$ext}", 'mime' => strtolower($m[2]), 'bytes' => $bytes, 'cid' => $cid];
                return "background-image:url('cid:{$cid}')";
            },
            $html
        ) ?? $html;
        return [$html, $atts];
    }
}
