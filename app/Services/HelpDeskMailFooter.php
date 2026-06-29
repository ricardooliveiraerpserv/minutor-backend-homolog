<?php

namespace App\Services;

/**
 * Rodapé/assinatura padrão dos e-mails do Help Desk: logo ERPSERV em destaque +
 * "Minutor" discreto. No e-mail o logo vai como imagem INLINE (cid, confiável nos
 * clientes); na prévia, como data: (renderiza no navegador).
 */
class HelpDeskMailFooter
{
    public const LOGO_CID = 'erpserv_logo@minutor';
    public const LOGO_WHITE_CID = 'erpserv_logo_white@minutor';

    private static function logoPath(): string
    {
        return public_path('logo-erpserv.png');
    }

    private static function whiteLogoPath(): string
    {
        return public_path('logo-erpserv-white.png');
    }

    /** Anexo inline (cid) do logo BRANCO — p/ cabeçalho colorido (legível no dark mode). */
    public static function inlineWhiteLogo(): ?array
    {
        $p = self::whiteLogoPath();
        if (!is_file($p)) return null;
        return ['name' => 'erpserv-white.png', 'mime' => 'image/png', 'bytes' => (string) file_get_contents($p), 'cid' => self::LOGO_WHITE_CID];
    }

    /** data:URI do logo BRANCO p/ a prévia. */
    public static function whiteLogoDataUri(): string
    {
        $p = self::whiteLogoPath();
        if (!is_file($p)) return '';
        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($p));
    }

    /** HTML do rodapé. $logoSrc = "cid:..." (e-mail) ou data:/URL (prévia). */
    public static function html(string $logoSrc): string
    {
        return '<div style="margin-top:26px;padding-top:16px;border-top:1px solid #e5e7eb;font-family:Arial,Helvetica,sans-serif">'
            . '<img src="' . $logoSrc . '" alt="ERPSERV" style="height:38px;width:auto;display:block;margin-bottom:8px;border:0" />'
            . '<div style="font-size:12px;color:#374151;font-weight:600">Central de Atendimento — ERPSERV Consultoria</div>'
            . '<div style="font-size:11px;color:#9ca3af;margin-top:3px">Mensagem automática · enviada via '
            . '<span style="color:#6b7280;font-weight:600">Minutor</span></div>'
            . '</div>';
    }

    /** Anexo inline (cid) do logo p/ o envio via Graph. Null se o arquivo não existir. */
    public static function inlineLogo(): ?array
    {
        $p = self::logoPath();
        if (!is_file($p)) return null;
        return ['name' => 'erpserv.png', 'mime' => 'image/png', 'bytes' => (string) file_get_contents($p), 'cid' => self::LOGO_CID];
    }

    /** data:URI do logo p/ a PRÉVIA no navegador. */
    public static function logoDataUri(): string
    {
        $p = self::logoPath();
        if (!is_file($p)) return '';
        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($p));
    }

    /** Rodapé pronto p/ a prévia (com logo data:). */
    public static function previewHtml(): string
    {
        $src = self::logoDataUri();
        return $src ? self::html($src) : '';
    }
}
