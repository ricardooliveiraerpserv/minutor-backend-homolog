<?php

namespace App\Services;

use App\Models\HelpDeskCommTemplate;

/**
 * Render server-side das comunicações: gera o HTML do e-mail a partir da ESTRUTURA (JSON),
 * aplicando TEMPLATES FIXOS por tipo. O usuário NÃO monta layout — só preenche conteúdo.
 * Identidade ERPSERV garantida; compatível com clientes de e-mail (tabelas + estilos inline,
 * gradiente com fallback bgcolor, logo branco via CID). Campos ricos passam por limpeza +
 * limitação de paleta (apenas roxo/verde/ciano/vermelho/cinza).
 *
 *  - marketing: HERO (gradiente + badge) · intro · problema · benefícios (ícone ✔) · CTA central ·
 *               bloco de autoridade · assinatura · footer institucional + opt-out
 *  - formal:    faixa enxuta · título · saudação · conteúdo · prazo · ação esperada · contato · assinatura · footer
 *  - aviso:     faixa enxuta · título · mensagem · data/hora · observação · footer (minimalista)
 */
class CommunicationRenderer
{
    public const TIPOS = ['aviso', 'formal', 'marketing'];

    /** Paleta permitida nos campos ricos (5 cores da marca + base/neutros que não contam como "cor livre"). */
    private const PALETTE = ['#6d28d9', '#16a34a', '#0891b2', '#dc2626', '#6b7280', '#1f2937', '#111827', '#000000', '#ffffff'];

    private const SOFT = '#ede9fe';      // tinta clara da marca (ícone de benefício / callouts)
    private const GRAD_END = '#4c1d95';

    public static function brandColor(): string
    {
        return HelpDeskCommTemplate::current()->primary_color ?: '#6d28d9';
    }

    // ───────────────────────────── E-MAIL COMPLETO ─────────────────────────────

    /** Documento HTML completo, conforme o tipo. $logo = "cid:..." (envio) ou data:/URL (prévia). */
    public static function render(string $tipo, string $title, array $s, ?string $logo = null): string
    {
        $logo  = $logo ?: ('cid:' . HelpDeskMailFooter::LOGO_WHITE_CID);
        $color = self::brandColor();

        $rows = match ($tipo) {
            'marketing' => self::marketingRows($title, $s, $color, $logo),
            'formal'    => self::formalRows($title, $s, $color, $logo),
            default     => self::avisoRows($title, $s, $color, $logo),
        };

        return self::document($rows);
    }

    private static function marketingRows(string $title, array $s, string $color, string $logo): string
    {
        $grad  = 'background:' . $color . ';background:linear-gradient(135deg,' . $color . ' 0%,' . self::GRAD_END . ' 100%)';
        $badge = trim((string) ($s['badge'] ?? ''));

        $hero = '<tr><td class="px" style="' . $grad . ';padding:32px 36px" bgcolor="' . $color . '">'
            . '<img src="' . $logo . '" alt="ERPSERV" height="26" style="height:26px;width:auto;display:block;border:0;margin-bottom:18px" />'
            . ($badge !== '' ? '<span style="display:inline-block;background:rgba(255,255,255,.18);color:#ffffff;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:5px 12px;border-radius:999px;margin-bottom:14px">' . e($badge) . '</span><br/>' : '')
            . '<div class="hero-t" style="font-size:30px;line-height:1.15;font-weight:800;color:#ffffff;margin:4px 0 0">' . e($title) . '</div>'
            . (!empty($s['subtitle']) ? '<div style="font-size:16px;line-height:1.45;color:rgba(255,255,255,.85);margin-top:12px">' . e($s['subtitle']) . '</div>' : '')
            . '</td></tr>';

        return $hero
            . self::richBlock($s['intro'] ?? '', '28px 36px 2px', 15)
            . self::richBlock($s['problema'] ?? '', '6px 36px 2px', 15)
            . self::benefitsIcons($s, $color)
            . self::ctaCentered($s, $color)
            . self::authority($s['autoridade'] ?? '', $color)
            . self::signatureSection($s["signature"] ?? null, $logo)
            . self::footerRow();
    }

    private static function formalRows(string $title, array $s, string $color, string $logo): string
    {
        $greeting = trim((string) ($s['greeting'] ?? '')) ?: 'Prezados,';

        return self::slimHeader($color, $logo)
            . self::titleBlock($title, 22)
            . '<tr><td class="px" style="padding:6px 36px 0;font-size:15px;color:#1f2937;font-weight:600">' . e($greeting) . '</td></tr>'
            . self::richBlock($s['content'] ?? '', '8px 36px 4px', 15)
            . self::labeledLine('Prazo', $s['prazo'] ?? '', $color)
            . self::labeledRich('Ação esperada', $s['acao_esperada'] ?? '', $color)
            . self::labeledLine('Contato', $s['contato'] ?? '', $color)
            . self::signatureSection($s["signature"] ?? null, $logo)
            . self::footerRow();
    }

    private static function avisoRows(string $title, array $s, string $color, string $logo): string
    {
        return self::slimHeader($color, $logo)
            . self::titleBlock($title, 19)
            . self::richBlock($s['content'] ?? '', '6px 36px 6px', 15)
            . self::labeledLine('Data/Hora', $s['datahora'] ?? '', $color)
            . self::labeledLine('Observação', $s['observacao'] ?? '', $color)
            . self::signatureSection($s["signature"] ?? null, $logo)
            . self::footerRow();
    }

    // ───────────────────────────── SEÇÕES ─────────────────────────────

    private static function slimHeader(string $color, string $logo): string
    {
        return '<tr><td class="px" style="background:' . $color . ';padding:16px 36px" bgcolor="' . $color . '">'
            . '<img src="' . $logo . '" alt="ERPSERV" height="24" style="height:24px;width:auto;display:block;border:0" /></td></tr>';
    }

    private static function titleBlock(string $title, int $size): string
    {
        return '<tr><td class="px" style="padding:26px 36px 2px">'
            . '<div style="font-size:' . $size . 'px;line-height:1.25;font-weight:700;color:#111827">' . e($title) . '</div></td></tr>';
    }

    /** Bloco de texto rico (conteúdo). Vazio → nada. */
    private static function richBlock(string $html, string $pad, int $size): string
    {
        if (trim(strip_tags($html)) === '') return '';
        return '<tr><td class="px" style="padding:' . $pad . ';font-size:' . $size . 'px;line-height:1.65;color:#1f2937">'
            . self::cleanRich($html) . '</td></tr>';
    }

    /** Linha rotulada (texto simples): "Rótulo: valor". Vazio → nada. */
    private static function labeledLine(string $label, ?string $value, string $color): string
    {
        $value = trim((string) $value);
        if ($value === '') return '';
        return '<tr><td class="px" style="padding:4px 36px;font-size:14px;line-height:1.6;color:#1f2937">'
            . '<span style="color:' . $color . ';font-weight:700">' . e($label) . ':</span> ' . e($value) . '</td></tr>';
    }

    /** Bloco rotulado com conteúdo rico. Vazio → nada. */
    private static function labeledRich(string $label, string $html, string $color): string
    {
        if (trim(strip_tags($html)) === '') return '';
        return '<tr><td class="px" style="padding:8px 36px 2px">'
            . '<div style="font-size:13px;font-weight:700;color:' . $color . ';margin-bottom:4px">' . e($label) . '</div>'
            . '<div style="font-size:14px;line-height:1.6;color:#1f2937">' . self::cleanRich($html) . '</div></td></tr>';
    }

    private static function benefitsList(array $s): array
    {
        return array_values(array_filter((array) ($s['benefits'] ?? []), fn ($b) => trim(strip_tags((string) $b)) !== ''));
    }

    /** Benefícios com ÍCONE (check em círculo da marca). */
    private static function benefitsIcons(array $s, string $color): string
    {
        $items = self::benefitsList($s);
        if (!$items) return '';
        $rows = '';
        foreach ($items as $b) {
            $rows .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px"><tr>'
                . '<td width="26" valign="top" style="width:26px">'
                . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
                . '<td width="24" height="24" align="center" valign="middle" style="width:24px;height:24px;background:' . self::SOFT . ';border-radius:50%;color:' . $color . ';font-size:13px;font-weight:700;line-height:24px">&#10003;</td>'
                . '</tr></table></td>'
                . '<td valign="top" style="font-size:14px;line-height:1.55;color:#1f2937;padding-left:12px">' . self::cleanRich((string) $b) . '</td>'
                . '</tr></table>';
        }
        return '<tr><td class="px" style="padding:16px 36px 4px">'
            . '<div style="font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:' . $color . ';margin:0 0 14px">Benefícios</div>'
            . $rows . '</td></tr>';
    }

    /** CTA central destacado. */
    private static function ctaCentered(array $s, string $color): string
    {
        if (empty($s['cta']['label']) || empty($s['cta']['url'])) return '';
        $url = filter_var($s['cta']['url'], FILTER_VALIDATE_URL) ? $s['cta']['url'] : '#';
        return '<tr><td align="center" style="padding:22px 36px 28px">'
            . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
            . '<td align="center" style="border-radius:10px;background:' . $color . '" bgcolor="' . $color . '">'
            . '<a href="' . e($url) . '" target="_blank" style="display:inline-block;padding:15px 42px;font-size:15px;font-weight:700;color:#ffffff;border-radius:10px">' . e($s['cta']['label']) . '</a>'
            . '</td></tr></table></td></tr>';
    }

    /** Bloco de autoridade (diferenciais/expertise) — callout claro da marca. */
    private static function authority(string $html, string $color): string
    {
        if (trim(strip_tags($html)) === '') return '';
        return '<tr><td class="px" style="padding:4px 36px 24px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::SOFT . ';border-radius:10px"><tr>'
            . '<td style="padding:16px 18px;border-left:4px solid ' . $color . '">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:' . $color . ';margin-bottom:6px">Por que a ERPSERV</div>'
            . '<div style="font-size:14px;line-height:1.6;color:#1f2937">' . self::cleanRich($html) . '</div>'
            . '</td></tr></table></td></tr>';
    }

    /** Assinatura padrão ERPSERV (já resolvida pelo controller: do usuário ou da empresa). */
    private static function signatureSection(?array $sig, string $headerLogo): string
    {
        if (!is_array($sig) || !SignatureRenderer::hasData($sig)) return '';
        // O e-mail é sempre sobre card BRANCO → tema light; com foto se houver.
        $mode = str_starts_with($headerLogo, 'cid:') ? 'cid' : 'data';
        return '<tr><td class="px" style="padding:14px 36px 24px;border-top:1px solid #eef0f3">' . SignatureRenderer::render($sig, $mode, true, 'light') . '</td></tr>';
    }

    /** Footer institucional fixo + opt-out. */
    private static function footerRow(): string
    {
        $tpl     = HelpDeskCommTemplate::current();
        $company = $tpl->company_name ?: 'ERPSERV Consultoria';
        $optMail = 'mailto:atendimento@erpserv.com.br?subject=' . rawurlencode('Cancelar inscrição');
        $minutor = '<div style="font-size:11px;color:#9ca3af;margin-top:8px">Mensagem automática · enviada via <span style="color:#6b7280;font-weight:600">Minutor</span></div>';
        $optout  = '<div style="font-size:11px;color:#9ca3af;margin-top:8px">Não deseja mais receber estes e-mails? '
            . '<a href="' . $optMail . '" style="color:#6b7280;text-decoration:underline">Cancelar inscrição</a>.</div>';

        return '<tr><td class="px" style="padding:20px 36px;background:#f7f7f9;border-top:1px solid #ececf1">'
            . '<div style="font-size:12px;line-height:1.5;color:#6b7280">'
            . '<span style="color:#374151;font-weight:700">Central de Atendimento — ' . e($company) . '</span></div>'
            . $minutor . $optout . '</td></tr>';
    }

    private static function document(string $rows): string
    {
        $css = 'body{margin:0;padding:0;background:#f1f1f4}a{text-decoration:none}'
            . '@media (max-width:620px){.cw{width:100%!important}.px{padding-left:22px!important;padding-right:22px!important}.hero-t{font-size:24px!important}}';

        return '<!doctype html><html lang="pt-BR"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            . '<style>' . $css . '</style></head>'
            . '<body style="margin:0;padding:0;background:#f1f1f4">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f1f4"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" class="cw" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;font-family:Arial,Helvetica,sans-serif">'
            . $rows
            . '</table></td></tr></table></body></html>';
    }

    // ─────────────── FRAGMENTO p/ a aba "Comunicados" (render inline) ───────────────

    public static function body(string $tipo, array $s, string $theme = 'light'): string
    {
        $dark   = $theme === 'dark';
        $color  = self::brandColor();                 // roxo da marca (fundo do CTA/badge)
        $accent = $dark ? '#C4B5FD' : $color;         // rótulos/subtítulo/✓
        $text   = $dark ? '#E5E7EB' : '#1f2937';
        $border = $dark ? '#374151' : '#e5e7eb';

        $p = fn ($html) => '<div style="font-size:14px;line-height:1.6;color:' . $text . ';margin:0 0 10px">' . self::cleanRich($html) . '</div>';
        $line = fn ($l, $v) => trim((string) $v) === '' ? '' : '<div style="font-size:13px;color:' . $text . ';margin:0 0 6px"><b style="color:' . $accent . '">' . e($l) . ':</b> ' . e($v) . '</div>';
        $out = '';

        if ($tipo === 'marketing') {
            if (!empty($s['badge'])) $out .= '<div style="display:inline-block;background:' . self::SOFT . ';color:' . $color . ';font-size:11px;font-weight:700;text-transform:uppercase;padding:4px 10px;border-radius:999px;margin-bottom:10px">' . e($s['badge']) . '</div>';
            if (!empty($s['subtitle'])) $out .= '<div style="font-size:15px;color:' . $accent . ';font-weight:600;margin:0 0 12px">' . e($s['subtitle']) . '</div>';
            if (trim(strip_tags($s['intro'] ?? '')) !== '') $out .= $p($s['intro']);
            if (trim(strip_tags($s['problema'] ?? '')) !== '') $out .= $p($s['problema']);
            foreach (self::benefitsList($s) as $i => $b) {
                $out .= '<div style="font-size:14px;line-height:1.6;color:' . $text . ';margin:0 0 6px"><span style="color:' . $accent . ';font-weight:bold">&#10003;</span> ' . self::cleanRich((string) $b) . '</div>';
            }
            if (!empty($s['cta']['label']) && !empty($s['cta']['url'])) {
                $url = filter_var($s['cta']['url'], FILTER_VALIDATE_URL) ? $s['cta']['url'] : '#';
                $out .= '<div style="margin:16px 0 6px"><a href="' . e($url) . '" target="_blank" style="display:inline-block;padding:11px 26px;font-size:14px;font-weight:bold;color:#fff;background:' . $color . ';border-radius:8px;text-decoration:none">' . e($s['cta']['label']) . '</a></div>';
            }
            if (trim(strip_tags($s['autoridade'] ?? '')) !== '') $out .= $p($s['autoridade']);
        } else {
            if (!empty($s['greeting'])) $out .= '<div style="font-size:14px;font-weight:600;color:' . $text . ';margin:0 0 8px">' . e($s['greeting']) . '</div>';
            if (trim(strip_tags($s['content'] ?? '')) !== '') $out .= $p($s['content']);
            $out .= $line('Prazo', $s['prazo'] ?? '');
            if (trim(strip_tags($s['acao_esperada'] ?? '')) !== '') $out .= $p($s['acao_esperada']);
            $out .= $line('Contato', $s['contato'] ?? '');
            $out .= $line('Data/Hora', $s['datahora'] ?? '');
            $out .= $line('Observação', $s['observacao'] ?? '');
        }

        $sig = $s['signature'] ?? null;
        if (is_array($sig) && SignatureRenderer::hasData($sig)) {
            $out .= '<div style="margin-top:16px;border-top:1px solid ' . $border . ';padding-top:12px">'
                . SignatureRenderer::render($sig, 'data', true, $theme) . '</div>';
        }

        return $out !== '' ? $out : '<div style="font-size:14px;color:#9ca3af">(sem conteúdo)</div>';
    }

    // ───────────────────────────── LIMPEZA / PALETA ─────────────────────────────

    public static function cleanRich(string $html): string
    {
        $html = preg_replace('#<\s*(script|iframe|object|embed|style|link|meta)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = preg_replace('#<\s*(script|iframe|object|embed|style|link|meta)\b[^>]*/?>#is', '', $html);
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*("|\')\s*javascript:[^"\']*("|\')#i', '$1=$2#$3', $html);
        return self::limitPalette($html);
    }

    public static function limitPalette(string $html): string
    {
        $allowed = array_map('strtolower', self::PALETTE);

        $html = preg_replace_callback('#color\s*:\s*([^;"\']+)#i', function ($m) use ($allowed) {
            $hex = self::normalizeHex(strtolower(trim($m[1])));
            return ($hex && in_array($hex, $allowed, true)) ? 'color:' . $hex : 'color:#1f2937';
        }, $html);

        $html = preg_replace_callback('#(<font[^>]*\bcolor\s*=\s*)("|\')([^"\']*)("|\')#i', function ($m) use ($allowed) {
            $hex = self::normalizeHex(strtolower(trim($m[3])));
            $use = ($hex && in_array($hex, $allowed, true)) ? $hex : '#1f2937';
            return $m[1] . $m[2] . $use . $m[4];
        }, $html);

        $html = preg_replace('#background(-color)?\s*:\s*[^;"\']+;?#i', '', $html);
        return $html;
    }

    private static function normalizeHex(string $v): ?string
    {
        if (preg_match('/^#([0-9a-f]{6})$/i', $v, $m)) return '#' . strtolower($m[1]);
        if (preg_match('/^#([0-9a-f]{3})$/i', $v, $m)) {
            $c = $m[1];
            return '#' . strtolower($c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2]);
        }
        if (preg_match('/^rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/i', $v, $m)) {
            return sprintf('#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        return null;
    }
}
