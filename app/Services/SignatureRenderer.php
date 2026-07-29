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

    /** Redes sociais da BIZIFY (assinatura dos usuários is_bizify). Handle @bizifyapp. */
    private const SOCIAL_BIZIFY = [
        ['label' => 'Instagram', 'url' => 'https://www.instagram.com/bizifyapp',       'icon' => 'instagram'],
        ['label' => 'LinkedIn',  'url' => 'https://www.linkedin.com/company/bizify',   'icon' => 'linkedin'],
        ['label' => 'YouTube',   'url' => 'https://www.youtube.com/@bizifyapp',        'icon' => 'youtube'],
        ['label' => 'Facebook',  'url' => 'https://www.facebook.com/bizifyapp',        'icon' => 'facebook'],
    ];

    /** Ícones PNG gerados (badges roxos) — public/sig-icons/. */
    private const ICONS = ['phone', 'whatsapp', 'email', 'web', 'location', 'instagram', 'linkedin', 'youtube', 'facebook', 'lets-do-it', 'lets-do-it-dark'];
    private const ICON_CID_PREFIX = 'sig_icon_';

    private static function iconPath(string $n): string { return public_path("sig-icons/$n.png"); }

    /** data:URI do logo BIZIFY (public/logo-bizify.png). */
    private static function bizifyLogoDataUri(): string
    {
        $p = public_path('logo-bizify.png');
        return is_file($p) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($p)) : '';
    }

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
    public static function companyDefault(string $brand = 'erpserv'): array
    {
        if ($brand === 'bizify') {
            return [
                'name'    => 'Bizify',
                'role'    => 'Central de Atendimento',
                'phone'   => '',                       // Bizify: só celular (opcional), sem fixo
                'phone2'  => '',
                'email'   => 'contato@bizify.com.br',
                'website' => 'bizify.com.br',
                'city'    => '',
                'photo'   => '',
                'brand'   => 'bizify',
            ];
        }
        return [
            'name'    => 'ERPSERV Consultoria',
            'role'    => 'Central de Atendimento',
            'phone'   => '',                       // celular (opcional) — institucional não tem
            'phone2'  => self::COMPANY_LANDLINE,
            'email'   => 'atendimento@erpserv.com.br',
            'website' => self::COMPANY_SITE,
            'city'    => self::COMPANY_CITY,
            'photo'   => '',
            'brand'   => 'erpserv',
        ];
    }

    /**
     * Monta os dados de render combinando: nome/e-mail AUTOMÁTICOS (do cadastro),
     * cargo/celular EDITÁVEIS (celular opcional), e telefone fixo/site/cidade FIXOS da empresa.
     * Sem cargo nem celular → assinatura institucional da empresa. $brand ('erpserv'|'bizify')
     * troca logo, redes, site e cores — a Bizify não tem fixo nem cidade (só celular opcional).
     */
    public static function resolveData(string $name, string $email, array $sig, string $brand = 'erpserv'): array
    {
        $role   = trim((string) ($sig['role'] ?? ''));
        $mobile = trim((string) ($sig['mobile'] ?? ''));
        $photo  = (string) ($sig['photo'] ?? '');

        if ($role === '' && $mobile === '' && $photo === '') {
            return self::companyDefault($brand);
        }
        $isBizify = $brand === 'bizify';
        return [
            'name'    => $name,
            'role'    => $role,
            'phone'   => $mobile,                                          // celular/whatsapp — opcional (some se vazio)
            'phone2'  => $isBizify ? '' : self::COMPANY_LANDLINE,          // Bizify não tem fixo
            'email'   => $email,
            'website' => $isBizify ? 'bizify.com.br' : self::COMPANY_SITE,
            'city'    => $isBizify ? '' : self::COMPANY_CITY,              // Bizify sem cidade
            'photo'   => $photo,
            'brand'   => $brand,
        ];
    }

    /** Resolve a assinatura a usar: a do usuário (nome/e-mail do cadastro) ou o padrão da empresa. */
    public static function resolveFor(?User $u): array
    {
        // Marca da assinatura: usuário da Bizify (is_bizify, derivado do home_company_id) → assinatura Bizify.
        $brand = ($u && $u->is_bizify) ? 'bizify' : 'erpserv';
        if (!$u) return self::companyDefault($brand);
        $sig = is_array($u->signature) ? $u->signature : [];
        // Cargo EFETIVO: se o usuário personalizou (custom_cargo), usa o cargo próprio; senão usa o
        // padrão do perfil (cadastro Cargos por Perfil) — sempre fresco, sem depender do que ficou salvo.
        // Retrocompat: assinaturas antigas guardaram o cargo em 'role' SEM o flag custom_cargo.
        // Se o flag não existe mas há um cargo salvo, honra o cargo digitado (senão o do perfil o
        // sobrescreveria e a mudança do usuário "sumia"). Com o flag presente, respeita a escolha.
        $custom = array_key_exists('custom_cargo', $sig)
            ? !empty($sig['custom_cargo'])
            : trim((string) ($sig['role'] ?? '')) !== '';
        $sig['role'] = $custom
            ? trim((string) ($sig['role'] ?? ''))
            : (string) \App\Models\ProfileCargo::forProfile($u->type);
        // Foto: ligada por padrão p/ quem tem foto de perfil; só não inclui se o usuário desmarcou (show_photo=false).
        $wantsPhoto = array_key_exists('show_photo', $sig) ? (bool) $sig['show_photo'] : true;
        if ($wantsPhoto && empty($sig['photo'])) {
            $dataUrl = $u->profilePhotoDataUrl();
            if ($dataUrl) $sig['photo'] = $dataUrl;
        }
        return self::resolveData((string) ($u->name ?? ''), (string) ($u->email ?? ''), $sig, $brand);
    }

    /** Há dados suficientes p/ renderizar? */
    public static function hasData(array $d): bool
    {
        return trim((string) ($d['name'] ?? '')) !== '';
    }

    /**
     * Faixa "LET'S DO IT" recriada em HTML/CSS (pílulas + texto), NÃO imagem. Escolha do usuário:
     * o CSS não ganha a "placa"/borda que o Apple Mail põe em imagem transparente no dark mode. Em
     * troca, no dark mode o Apple Mail clareia a cor (não fica idêntica ao logo); no claro fica igual.
     * $c = cor (no claro = cor do logo #4a2583). Pílulas mapeadas do PNG (blobs, escala 0.45).
     */
    private static function taglineHtml(string $c): string
    {
        // O Exchange REMOVE position:absolute → posicionamos por LINHAS empilhadas (display:block) +
        // margin-left (que o Exchange preserva). Offsets horizontais exatos; níveis verticais por linha.
        $p = fn (int $w, int $h, int $ml) => '<span style="display:inline-block;margin-left:' . $ml . 'px;width:' . $w . 'px;height:' . $h . 'px;border-radius:' . ($w === $h ? '50%' : ((int) floor($h / 2)) . 'px') . ';background:' . $c . '"></span>';
        // A linha NÃO leva line-height:0 — no Apple Mail isso zera a caixa e as pílulas somem (bug do v13).
        $row = fn (int $h, int $mt, int $mb, string $in) => '<span style="display:block;height:' . $h . 'px;margin:' . $mt . 'px 0 ' . $mb . 'px 0">' . $in . '</span>';
        $left = '<span style="display:inline-block;vertical-align:middle;width:74px;font-size:0;line-height:0">'
            . $row(5, 2, 1, $p(14, 5, 49))
            . $row(7, 0, 0, $p(8, 7, 11) . $p(22, 7, 0) . $p(17, 5, 15))
            . $row(6, 0, 0, $p(16, 5, 2) . $p(13, 6, 35))
            . $row(6, 0, 0, $p(6, 6, 44))
            . '</span>';
        $right = '<span style="display:inline-block;vertical-align:middle;width:42px;font-size:0;line-height:0;margin-left:7px">'
            . $row(5, 5, 1, $p(14, 5, 25))
            . $row(8, 0, 0, $p(8, 7, 0) . $p(22, 8, 1))
            . $row(6, 0, 0, $p(13, 6, 29))
            . '</span>';
        $dash = '<span style="display:inline-block;vertical-align:middle;width:13px;height:5px;border-radius:2px;background:' . $c . ';margin:0 7px"></span>';
        $text = '<span style="display:inline-block;vertical-align:middle;font-family:\'Century Gothic\',\'Twentieth Century\',\'Trebuchet MS\',Arial,sans-serif;font-size:17px;font-weight:400;letter-spacing:1px;color:' . $c . ';line-height:1">LET&#8217;S&nbsp;DO&nbsp;<span style="font-weight:800">IT</span></span>';
        return '<span style="display:inline-block;vertical-align:middle;font-size:0;line-height:0;white-space:nowrap">'
            . $left . $dash . $text . $right . '</span>';
    }

    /** Uma linha de contato (ícone PNG + conteúdo) com cor de texto do tema. */
    private static function contactLine(string $icon, string $mode, string $html, string $textColor): string
    {
        $img = '<img src="' . self::iconSrc($icon, $mode) . '" width="20" height="20" alt="" border="0" style="width:20px;height:20px;display:inline-block;vertical-align:middle;border:0;outline:none;text-decoration:none;margin-right:8px" />';
        return '<div style="margin:0 0 7px;font-size:11px;color:' . $textColor . ';line-height:24px;white-space:nowrap">' . $img . $html . '</div>';
    }

    /**
     * HTML da assinatura — GRID 3 COLUNAS: (1) logo + bloco usuário [foto | nome/cargo];
     * (2) faixa "LET'S DO IT" + contatos VERTICAIS; (3) redes VERTICAIS.
     * $iconMode = 'cid' (e-mail) ou 'data' (sistema/prévia). $showPhoto: exibe a foto se houver.
     * $theme = 'light'|'dark' (ajusta texto, logo e faixa).
     * $showTagline: exibe a faixa "LET'S DO IT". Desligado no E-MAIL — o Apple Mail em dark mode
     *   desenha uma "placa" (borda branca) atrás de imagens transparentes e não há CSS que a remova.
     */
    public static function render(array $d, string $iconMode = 'data', bool $showPhoto = true, string $theme = 'light', bool $showTagline = true): string
    {
        $dark = $theme === 'dark';
        $isBizify = ($d['brand'] ?? 'erpserv') === 'bizify';
        $name = trim((string) ($d['name'] ?? ''));
        $role = trim((string) ($d['role'] ?? ''));

        // Paleta por tema. Bizify: nome em azul-marinho da marca.
        $nameColor = $dark ? '#ffffff' : ($isBizify ? '#2b2e83' : '#111827');
        $roleColor = $dark ? '#9CA3AF' : '#6b7280';
        $textColor = $dark ? '#E5E7EB' : '#111827';
        $linkColor = $dark ? '#93c5fd' : '#1d4ed8'; // endereços (e-mail/site) em AZUL

        // Logo: Bizify usa o logo colorido (data:URI → o treatBody converte p/ cid no e-mail).
        // ERPSERV: soft-white no escuro; roxo no claro.
        $logoSrc = $isBizify
            ? self::bizifyLogoDataUri()
            : ($iconMode === 'cid'
                ? ('cid:' . ($dark ? HelpDeskMailFooter::LOGO_WHITE_CID : HelpDeskMailFooter::LOGO_CID))
                : ($dark ? self::softLogoDataUri() : HelpDeskMailFooter::logoDataUri()));

        // ── COLUNA ESQUERDA: logo + bloco usuário (foto à ESQUERDA do nome) ──
        $photoCell = '';
        if ($showPhoto && !empty($d['photo']) && (Str::startsWith($d['photo'], 'data:image') || filter_var($d['photo'], FILTER_VALIDATE_URL))) {
            // Foto REDONDA como BACKGROUND-IMAGE (não <img>): o Apple Mail em dark mode desenha uma
            // "placa"/borda atrás de <img> (a foto recortada em círculo expõe cantos transparentes e
            // vira alvo). Como background-image de um <div>, o cliente NÃO adiciona a placa → sem borda,
            // e continua redonda. No e-mail o data: vira cid via HelpDeskReplyMailer::treatBody.
            $photoCell = '<td valign="top" width="54" style="width:54px;min-width:54px;vertical-align:top;padding-right:10px">'
                . '<span style="display:inline-block;width:44px;height:44px;border-radius:50%;'
                . 'background-image:url(\'' . e($d['photo']) . '\');background-size:cover;background-position:center;background-repeat:no-repeat"></span></td>';
        }
        $userBlock = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px"><tr>'
            . $photoCell
            . '<td valign="top" style="vertical-align:top">'
            .   '<div style="font-size:13px;font-weight:bold;color:' . $nameColor . ';text-transform:uppercase;line-height:1.2;white-space:nowrap">' . e($name) . '</div>'
            .   ($role !== '' ? '<div style="font-size:11px;color:' . $roleColor . ';text-transform:uppercase;line-height:1.3;white-space:nowrap">' . e($role) . '</div>' : '')
            . '</td></tr></table>';
        $leftInner = '<img src="' . $logoSrc . '" alt="' . ($isBizify ? 'Bizify' : 'ERPSERV') . '" width="150" border="0" style="width:150px;max-width:100%;height:auto;display:block;border:0;outline:none" />'
            . $userBlock;

        // ── BLOCO DIREITO: topo (faixa/handle + redes) + contatos em COLUNA ÚNICA ──
        // ERPSERV: faixa "LET'S DO IT" em CSS. Bizify: handle @bizifyapp (azul da marca).
        $bizHandle = '<span style="display:inline-block;vertical-align:middle;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;letter-spacing:.3px;color:' . ($dark ? '#5ec5f0' : '#29abe2') . '">@bizifyapp</span>';
        $banner = $showTagline ? ($isBizify ? $bizHandle : self::taglineHtml($dark ? '#C4B5FD' : '#4a2583')) : '';
        $social = '';
        foreach (($isBizify ? self::SOCIAL_BIZIFY : self::SOCIAL) as $s) {
            $social .= '<a href="' . $s['url'] . '" target="_blank" title="' . $s['label'] . '" style="text-decoration:none;border:0;outline:none;margin-left:4px;display:inline-block">'
                . '<img src="' . self::iconSrc($s['icon'], $iconMode) . '" width="20" height="20" alt="' . $s['label'] . '" border="0" style="width:20px;height:20px;border:0;outline:none;text-decoration:none;display:inline-block;vertical-align:middle" /></a>';
        }
        $topRow = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:14px"><tr>'
            . '<td valign="middle" style="vertical-align:middle">' . $banner . '</td>'
            . '<td valign="middle" align="right" style="vertical-align:middle;text-align:right;white-space:nowrap">' . $social . '</td>'
            . '</tr></table>';

        // Contatos em COLUNA ÚNICA (cada um em linha própria de largura total). Antes eram 2 colunas
        // estreitas de <td> fixo → no mobile o e-mail/endereço era espremido e QUEBRAVA caractere a
        // caractere, invadindo os botões. Em linha inteira, cabe sem quebrar.
        $contacts = '';
        if (!empty($d['phone']))  $contacts .= self::contactLine('whatsapp', $iconMode, e($d['phone']), $textColor);
        if (!empty($d['phone2'])) $contacts .= self::contactLine('phone', $iconMode, e($d['phone2']), $textColor);
        if (!empty($d['email']))  $contacts .= self::contactLine('email', $iconMode, '<a href="mailto:' . e($d['email']) . '" style="color:' . $linkColor . ';text-decoration:underline">' . e($d['email']) . '</a>', $textColor);
        if (!empty($d['city']))   $contacts .= self::contactLine('location', $iconMode, e($d['city']), $textColor);
        if (!empty($d['website'])) {
            $href = preg_match('#^https?://#i', $d['website']) ? $d['website'] : 'https://' . $d['website'];
            $contacts .= self::contactLine('web', $iconMode, '<a href="' . e($href) . '" target="_blank" style="color:' . $linkColor . ';text-decoration:underline">' . e($d['website']) . '</a>', $textColor);
        }
        $rightInner = $topRow . $contacts;

        // Layout FLUID-HYBRID: no desktop as duas colunas ficam lado a lado (ghost table p/ Outlook
        // Windows); no MOBILE a coluna direita quebra para baixo sozinha (inline-block + max-width:100%),
        // SEM media query — funciona no Outlook iOS/Gmail/Apple Mail. Fim do overlap com os botões.
        return '<div style="max-width:520px;font-family:Arial,Helvetica,sans-serif;margin-top:6px">'
            . '<!--[if mso]><table role="presentation" width="516" cellpadding="0" cellspacing="0" border="0"><tr><td width="164" valign="top"><![endif]-->'
            . '<div style="display:inline-block;vertical-align:top;width:164px;max-width:100%;padding-right:12px;box-sizing:border-box">' . $leftInner . '</div>'
            . '<!--[if mso]></td><td width="336" valign="top"><![endif]-->'
            . '<div style="display:inline-block;vertical-align:top;width:336px;max-width:100%;box-sizing:border-box">' . $rightInner . '</div>'
            . '<!--[if mso]></td></tr></table><![endif]-->'
            . '</div>';
    }
}
