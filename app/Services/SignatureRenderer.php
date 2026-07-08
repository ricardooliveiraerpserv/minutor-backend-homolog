<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Render da ASSINATURA padrão ERPSERV a partir de dados ESTRUTURADOS (sem HTML livre).
 * Layout fixo (logo à esquerda + dados à direita), cores da marca, table-based p/ compatibilidade
 * com Outlook/Gmail. Redes sociais são constantes da empresa (Facebook/Instagram/LinkedIn).
 */
class SignatureRenderer
{
    private const BRAND = '#5b21b6';   // roxo principal da marca

    /** Redes sociais institucionais (links obrigatórios). */
    private const SOCIAL = [
        ['label' => 'Instagram', 'url' => 'https://www.instagram.com/erpserv',        'icon' => 'instagram'],
        ['label' => 'LinkedIn',  'url' => 'https://www.linkedin.com/company/erpserv', 'icon' => 'linkedin'],
        ['label' => 'YouTube',   'url' => 'https://www.youtube.com/@erpserv',          'icon' => 'youtube'],
        ['label' => 'Facebook',  'url' => 'https://www.facebook.com/erpserv',          'icon' => 'facebook'],
    ];

    /** Ícones PNG gerados (badges roxos) — public/sig-icons/. */
    private const ICONS = ['phone', 'whatsapp', 'email', 'web', 'location', 'instagram', 'linkedin', 'youtube', 'facebook', 'lets-do-it', 'lets-do-it-dark'];
    private const ICON_CID_PREFIX = 'sig_icon_';

    private static function iconPath(string $n): string { return public_path("sig-icons/$n.png"); }

    /** data:URI do logo soft-white (#E5E7EB) p/ fundo escuro; fallback ao branco. */
    private static function softLogoDataUri(): string
    {
        $p = public_path('logo-erpserv-soft.png');
        if (is_file($p)) return 'data:image/png;base64,' . base64_encode((string) file_get_contents($p));
        return HelpDeskMailFooter::whiteLogoDataUri();
    }
    public static function iconCid(string $n): string { return self::ICON_CID_PREFIX . $n . '@minutor'; }

    /** src do ícone: 'cid' (e-mail) ou 'data' (prévia/sistema). */
    private static function iconSrc(string $n, string $mode): string
    {
        if ($mode === 'cid') return 'cid:' . self::iconCid($n);
        $p = self::iconPath($n);
        return is_file($p) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($p)) : '';
    }

    /** Anexos inline (CID) dos ícones da assinatura — p/ o envio via Graph. */
    public static function inlineAssets(): array
    {
        $out = [];
        foreach (self::ICONS as $n) {
            $p = self::iconPath($n);
            if (is_file($p)) $out[] = ['name' => "$n.png", 'mime' => 'image/png', 'bytes' => (string) file_get_contents($p), 'cid' => self::iconCid($n)];
        }
        return $out;
    }

    /** Campos editáveis por usuário (o resto é automático/fixo). */
    public const FIELDS = ['role', 'mobile', 'photo'];

    /** Dados FIXOS da empresa (não mudam por usuário). */
    private const COMPANY_LANDLINE = '(11) 3230.9647';
    private const COMPANY_SITE     = 'www.erpserv.com.br';
    private const COMPANY_CITY     = 'São Paulo/SP - Brasil';

    /** Assinatura padrão da empresa (fallback quando o usuário não tem assinatura). */
    public static function companyDefault(): array
    {
        return [
            'name'    => 'ERPSERV Consultoria',
            'role'    => 'Central de Atendimento',
            'phone'   => '',                       // celular (opcional) — institucional não tem
            'phone2'  => self::COMPANY_LANDLINE,
            'email'   => 'atendimento@erpserv.com.br',
            'website' => self::COMPANY_SITE,
            'city'    => self::COMPANY_CITY,
            'photo'   => '',
        ];
    }

    /**
     * Monta os dados de render combinando: nome/e-mail AUTOMÁTICOS (do cadastro),
     * cargo/celular EDITÁVEIS (celular opcional), e telefone fixo/site/cidade FIXOS da empresa.
     * Sem cargo nem celular → assinatura institucional da empresa.
     */
    public static function resolveData(string $name, string $email, array $sig): array
    {
        $role   = trim((string) ($sig['role'] ?? ''));
        $mobile = trim((string) ($sig['mobile'] ?? ''));
        $photo  = (string) ($sig['photo'] ?? '');

        if ($role === '' && $mobile === '' && $photo === '') {
            return self::companyDefault();
        }
        return [
            'name'    => $name,
            'role'    => $role,
            'phone'   => $mobile,                  // celular/whatsapp — opcional (some se vazio)
            'phone2'  => self::COMPANY_LANDLINE,   // fixo
            'email'   => $email,
            'website' => self::COMPANY_SITE,
            'city'    => self::COMPANY_CITY,
            'photo'   => $photo,
        ];
    }

    /** Resolve a assinatura a usar: a do usuário (nome/e-mail do cadastro) ou o padrão da empresa. */
    public static function resolveFor(?User $u): array
    {
        if (!$u) return self::companyDefault();
        $sig = is_array($u->signature) ? $u->signature : [];
        // Cargo EFETIVO: se o usuário personalizou (custom_cargo), usa o cargo próprio; senão usa o
        // padrão do perfil (cadastro Cargos por Perfil) — sempre fresco, sem depender do que ficou salvo.
        $custom = !empty($sig['custom_cargo']);
        $sig['role'] = $custom
            ? trim((string) ($sig['role'] ?? ''))
            : (string) \App\Models\ProfileCargo::forProfile($u->type);
        // Foto: ligada por padrão p/ quem tem foto de perfil; só não inclui se o usuário desmarcou (show_photo=false).
        $wantsPhoto = array_key_exists('show_photo', $sig) ? (bool) $sig['show_photo'] : true;
        if ($wantsPhoto && empty($sig['photo'])) {
            $dataUrl = $u->profilePhotoDataUrl();
            if ($dataUrl) $sig['photo'] = $dataUrl;
        }
        return self::resolveData((string) ($u->name ?? ''), (string) ($u->email ?? ''), $sig);
    }

    /** Há dados suficientes p/ renderizar? */
    public static function hasData(array $d): bool
    {
        return trim((string) ($d['name'] ?? '')) !== '';
    }

    /** Uma linha de contato (ícone PNG + conteúdo) com cor de texto do tema. */
    private static function contactLine(string $icon, string $mode, string $html, string $textColor): string
    {
        $img = '<img src="' . self::iconSrc($icon, $mode) . '" width="28" height="28" alt="" style="width:28px;height:28px;display:inline-block;vertical-align:middle;border:0;margin-right:9px" />';
        return '<div style="margin:0 0 9px;font-size:14px;color:' . $textColor . ';line-height:28px;white-space:nowrap">' . $img . $html . '</div>';
    }

    /**
     * HTML da assinatura — GRID 3 COLUNAS: (1) logo + bloco usuário [foto | nome/cargo];
     * (2) faixa "LET'S DO IT" + contatos VERTICAIS; (3) redes VERTICAIS.
     * $iconMode = 'cid' (e-mail) ou 'data' (sistema/prévia). $showPhoto: exibe a foto se houver.
     * $theme = 'light'|'dark' (ajusta texto, logo e faixa).
     */
    public static function render(array $d, string $iconMode = 'data', bool $showPhoto = true, string $theme = 'light'): string
    {
        $dark = $theme === 'dark';
        $name = trim((string) ($d['name'] ?? ''));
        $role = trim((string) ($d['role'] ?? ''));

        // Paleta por tema.
        $nameColor = $dark ? '#ffffff' : '#111827';
        $roleColor = $dark ? '#9CA3AF' : '#6b7280';
        $textColor = $dark ? '#E5E7EB' : '#111827';
        $linkColor = $dark ? '#C4B5FD' : self::BRAND;

        // Logo: soft-white no escuro; roxo no claro.
        $logoSrc = $iconMode === 'cid'
            ? ('cid:' . ($dark ? HelpDeskMailFooter::LOGO_WHITE_CID : HelpDeskMailFooter::LOGO_CID))
            : ($dark ? self::softLogoDataUri() : HelpDeskMailFooter::logoDataUri());
        $taglineIcon = $dark ? 'lets-do-it-dark' : 'lets-do-it';

        // ── COLUNA ESQUERDA: logo + bloco usuário (foto à ESQUERDA do nome) ──
        $photoCell = '';
        if ($showPhoto && !empty($d['photo']) && (Str::startsWith($d['photo'], 'data:image') || filter_var($d['photo'], FILTER_VALIDATE_URL))) {
            $photoCell = '<td valign="top" width="54" style="width:54px;min-width:54px;vertical-align:top;padding-right:10px">'
                . '<img src="' . e($d['photo']) . '" width="44" height="44" alt="" style="width:44px;min-width:44px;max-width:44px;height:44px;border-radius:50%;object-fit:cover;display:block;border:0" /></td>';
        }
        $userBlock = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px"><tr>'
            . $photoCell
            . '<td valign="top" style="vertical-align:top">'
            .   '<div style="font-size:16px;font-weight:bold;color:' . $nameColor . ';text-transform:uppercase;line-height:1.2;white-space:nowrap">' . e($name) . '</div>'
            .   ($role !== '' ? '<div style="font-size:13px;color:' . $roleColor . ';text-transform:uppercase;line-height:1.3;white-space:nowrap">' . e($role) . '</div>' : '')
            . '</td></tr></table>';
        $left = '<td valign="top" style="vertical-align:top;padding-right:22px">'
            . '<img src="' . $logoSrc . '" alt="ERPSERV" width="170" style="width:170px;max-width:100%;height:auto;display:block;border:0" />'
            . $userBlock
            . '</td>';

        // ── BLOCO DIREITO: topo (faixa "LET'S DO IT" + redes HORIZONTAIS) + contatos em GRID 2 colunas ──
        $banner = '<img src="' . self::iconSrc($taglineIcon, $iconMode) . '" alt="LET&#39;S DO IT" width="260" style="width:260px;max-width:100%;height:auto;display:block;border:0" />';
        $social = '';
        foreach (self::SOCIAL as $s) {
            $social .= '<a href="' . $s['url'] . '" target="_blank" title="' . $s['label'] . '" style="text-decoration:none;margin-left:8px;display:inline-block">'
                . '<img src="' . self::iconSrc($s['icon'], $iconMode) . '" width="30" height="30" alt="' . $s['label'] . '" style="width:30px;height:30px;border:0;display:inline-block;vertical-align:middle" /></a>';
        }
        $topRow = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:16px"><tr>'
            . '<td valign="middle" style="vertical-align:middle">' . $banner . '</td>'
            . '<td valign="middle" align="right" style="vertical-align:middle;text-align:right;white-space:nowrap">' . $social . '</td>'
            . '</tr></table>';

        // contatos em 2 colunas: A (celular/fixo/cidade) | B (e-mail/site)
        $colA = '';
        if (!empty($d['phone']))  $colA .= self::contactLine('whatsapp', $iconMode, e($d['phone']), $textColor);
        if (!empty($d['phone2'])) $colA .= self::contactLine('phone', $iconMode, e($d['phone2']), $textColor);
        if (!empty($d['city']))   $colA .= self::contactLine('location', $iconMode, e($d['city']), $textColor);
        $colB = '';
        if (!empty($d['email']))  $colB .= self::contactLine('email', $iconMode, '<a href="mailto:' . e($d['email']) . '" style="color:' . $linkColor . ';text-decoration:underline">' . e($d['email']) . '</a>', $textColor);
        if (!empty($d['website'])) {
            $href = preg_match('#^https?://#i', $d['website']) ? $d['website'] : 'https://' . $d['website'];
            $colB .= self::contactLine('web', $iconMode, '<a href="' . e($href) . '" target="_blank" style="color:' . $linkColor . ';text-decoration:underline">' . e($d['website']) . '</a>', $textColor);
        }
        $grid = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td valign="top" style="vertical-align:top;padding-right:34px">' . $colA . '</td>'
            . '<td valign="top" style="vertical-align:top">' . $colB . '</td>'
            . '</tr></table>';

        $right = '<td valign="top" style="vertical-align:top">' . $topRow . $grid . '</td>';

        return '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;border-collapse:collapse;font-family:Arial,Helvetica,sans-serif;margin-top:6px">'
            . '<tr>' . $left . $right . '</tr></table>';
    }
}
