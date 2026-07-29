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

    private static function iconPath(string $n, string $brand = 'erpserv'): string
    {
        if ($brand === 'bizify') {
            $bz = public_path("sig-icons-bizify/$n.png");
            if (is_file($bz)) return $bz; // ícones azuis Bizify; se faltar algum, cai no roxo padrão
        }
        return public_path("sig-icons/$n.png");
    }

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
    private static function iconSrc(string $n, string $mode, string $brand = 'erpserv'): string
    {
        // modo cid (comunicação): usa os ícones padrão (roxo) — a via principal do HD é 'data'.
        if ($mode === 'cid') return 'cid:' . self::iconCid($n);
        $p = self::iconPath($n, $brand);
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
    public static function resolveData(string $name, string $email, array $sig, string $brand = 'erpserv', string $homeBrand = 'erpserv'): array
    {
        $role   = trim((string) ($sig['role'] ?? ''));
        $mobile = trim((string) ($sig['mobile'] ?? ''));
        $photo  = (string) ($sig['photo'] ?? '');

        if ($role === '' && $mobile === '' && $photo === '') {
            return self::companyDefault($brand);
        }
        $isBizify = $brand === 'bizify';
        // E-mail por EMPRESA BASE: a assinatura do brand da empresa base usa o e-mail do CADASTRO;
        // a assinatura do OUTRO brand usa o e-mail SECUNDÁRIO (alt_email). Secundário vazio → sem
        // linha de e-mail naquela assinatura (não cai no principal). Compat: aceita bizify_email antigo.
        $altEmail = trim((string) ($sig['alt_email'] ?? $sig['bizify_email'] ?? ''));
        $emailForBrand = ($brand === $homeBrand) ? $email : $altEmail;
        return [
            'name'    => $name,
            'role'    => $role,
            'phone'   => $mobile,                                          // celular/whatsapp — opcional (some se vazio)
            'phone2'  => $isBizify ? '' : self::COMPANY_LANDLINE,          // Bizify não tem fixo
            'email'   => $emailForBrand,
            'website' => $isBizify ? 'bizify.com.br' : self::COMPANY_SITE,
            'city'    => $isBizify ? '' : self::COMPANY_CITY,              // Bizify sem cidade
            'photo'   => $photo,
            'brand'   => $brand,
        ];
    }

    /** Resolve a assinatura a usar: a do usuário (nome/e-mail do cadastro) ou o padrão da empresa. */
    public static function resolveFor(?User $u, ?string $forceBrand = null): array
    {
        // Marca da assinatura: usuário da Bizify (is_bizify, derivado do home_company_id) → assinatura Bizify.
        // $forceBrand permite o chamador impor a marca (ex.: resposta de um chamado da Bizify → assinatura
        // Bizify mesmo que o AGENTE seja admin ERPSERV — assim admins têm as DUAS conforme o contexto).
        // Empresa BASE do usuário (define qual assinatura usa o e-mail do cadastro).
        $homeBrand = ($u && $u->is_bizify) ? 'bizify' : 'erpserv';
        $brand = $forceBrand ?: $homeBrand;
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
        return self::resolveData((string) ($u->name ?? ''), (string) ($u->email ?? ''), $sig, $brand, $homeBrand);
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
    /** Grade de pontinhos (decoração Bizify, canto superior direito). Spans inline-block (email-safe). */
    private static function bizifyDots(string $c, int $cols = 8, int $rows = 6): string
    {
        $dot = '<span style="display:inline-block;width:3px;height:3px;border-radius:50%;background:' . $c . ';margin:0 5px 0 0"></span>';
        $row = str_repeat($dot, $cols);
        $out = '';
        for ($i = 0; $i < $rows; $i++) $out .= '<span style="display:block;line-height:8px;font-size:0">' . $row . '</span>';
        return '<span style="display:inline-block;font-size:0;line-height:0">' . $out . '</span>';
    }

    /** Gráfico de barras crescentes (outline) — decoração Bizify. */
    private static function bizifyBars(string $c): string
    {
        $bar = fn (int $h) => '<span style="display:inline-block;width:9px;height:' . $h . 'px;border:1px solid ' . $c . ';border-radius:2px 2px 0 0;margin:0 3px 0 0;vertical-align:bottom"></span>';
        return '<span style="display:inline-block;vertical-align:bottom;font-size:0">' . $bar(7) . $bar(13) . $bar(19) . $bar(27) . $bar(35) . '</span>';
    }

    /** Anel/círculo (Outlook sem border-radius degrada p/ quadrado — aceitável). Decoração Bizify. */
    private static function bizifyRing(string $c): string
    {
        return '<span style="display:inline-block;width:26px;height:26px;border:5px solid ' . $c . ';border-radius:50%;vertical-align:bottom;margin-left:8px"></span>';
    }

    /** "+" duplos (decoração Bizify, topo esquerdo). */
    private static function bizifyPlus(string $c): string
    {
        return '<span style="display:inline-block;font-family:Arial,sans-serif;color:' . $c . ';font-weight:700;line-height:1">'
            . '<span style="font-size:22px">+</span><span style="font-size:14px;vertical-align:top">+</span></span>';
    }

    private static function contactLine(string $icon, string $mode, string $html, string $textColor, string $brand = 'erpserv'): string
    {
        $img = '<img src="' . self::iconSrc($icon, $mode, $brand) . '" width="20" height="20" alt="" border="0" style="width:20px;height:20px;display:inline-block;vertical-align:middle;border:0;outline:none;text-decoration:none;margin-right:8px" />';
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
        $left = '<td valign="top" style="vertical-align:top;padding-right:12px">'
            . '<img src="' . $logoSrc . '" alt="ERPSERV" width="150" border="0" style="width:150px;max-width:100%;height:auto;display:block;border:0;outline:none" />'
            . $userBlock
            . '</td>';

        // ── BLOCO DIREITO: topo (faixa/handle + redes HORIZONTAIS) + contatos em GRID 2 colunas ──
        // ERPSERV: faixa "LET'S DO IT" em CSS. Bizify: handle @bizifyapp (azul da marca).
        $bizHandle = '<span style="display:inline-block;vertical-align:middle;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;letter-spacing:.3px;color:' . ($dark ? '#5ec5f0' : '#29abe2') . '">@bizifyapp</span>';
        $banner = $showTagline ? ($isBizify ? $bizHandle : self::taglineHtml($dark ? '#C4B5FD' : '#4a2583')) : '';
        $social = '';
        foreach (($isBizify ? self::SOCIAL_BIZIFY : self::SOCIAL) as $s) {
            $social .= '<a href="' . $s['url'] . '" target="_blank" title="' . $s['label'] . '" style="text-decoration:none;border:0;outline:none;margin-left:4px;display:inline-block">'
                . '<img src="' . self::iconSrc($s['icon'], $iconMode, $isBizify ? 'bizify' : 'erpserv') . '" width="20" height="20" alt="' . $s['label'] . '" border="0" style="width:20px;height:20px;border:0;outline:none;text-decoration:none;display:inline-block;vertical-align:middle" /></a>';
        }
        // ── LAYOUT BIZIFY (fiel ao modelo): logo + redes + @bizifyapp à ESQUERDA; nome + contatos no
        //    MEIO; grafismos (pontinhos / barras / anel PARCIAL) à DIREITA. Estrutura própria (o Bizify
        //    NÃO usa a estrutura da ERPSERV). Decorações como PNG (anel parcial, balão, telefone, nuvem).
        if ($isBizify) {
            // Blocos como DATA URI (embutidos): elimina o cache de imagem do navegador que servia versão
            // antiga (por URL o browser nem pedia o arquivo — disk cache). Renderiza porque as colunas
            // têm largura fixa (o que travava antes era o colapso da coluna, não o data URI). No e-mail o
            // treatBody converte o data: em anexo inline (cid). PNG 256 cores → data URI leve (~47KB).
            $blockUri = function (string $f): string {
                $p = public_path("sig-icons-bizify/$f.png");
                return is_file($p) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($p)) : '';
            };
            $bzContacts = '';
            if (!empty($d['phone']))   $bzContacts .= self::contactLine('phone', $iconMode, e($d['phone']), $textColor, 'bizify');
            if (!empty($d['email']))   $bzContacts .= self::contactLine('email', $iconMode, '<a href="mailto:' . e($d['email']) . '" style="color:' . $linkColor . ';text-decoration:underline">' . e($d['email']) . '</a>', $textColor, 'bizify');
            if (!empty($d['website'])) {
                $bzHref = preg_match('#^https?://#i', $d['website']) ? $d['website'] : 'https://' . $d['website'];
                $bzContacts .= self::contactLine('web', $iconMode, '<a href="' . e($bzHref) . '" target="_blank" style="color:' . $linkColor . ';text-decoration:underline">' . e($d['website']) . '</a>', $textColor, 'bizify');
            }
            // Blocos decorativos = recortes da ARTE OFICIAL Bizify (logo bicolor, redes redondas, "+",
            // balão, telefone, nuvem à esq.; pontinhos, barras, anel à dir.) → idênticos ao original.
            $leftBlock  = $blockUri('block-left-hd');
            $rightBlock = $blockUri('block-right-hd');
            // Larguras EXPLÍCITAS por coluna (sem width="1"/max-width): numa tabela shrink de 3 colunas
            // o navegador dava toda a largura ao meio e colapsava col1/col3 (blocos "sumiam").
            // Mesmas PROPORÇÕES do template (bloco DIREITO o mais largo), porém em tamanho menor (~730px).
            // Texto centralizado verticalmente contra os blocos.
            $col1 = '<td width="225" valign="middle" style="width:225px;vertical-align:middle;padding-right:8px">'
                . ($leftBlock !== '' ? '<img src="' . $leftBlock . '" alt="Bizify" width="215" border="0" style="width:215px;height:auto;display:block;border:0;outline:none">' : '')
                . '</td>';
            // Bloco NOME/FOTO da Bizify: foto à ESQUERDA, nome+cargo CENTRALIZADOS verticalmente com ela.
            // Nome em fonte arredondada (aprox. da marca) + navy Bizify, title-case (não maiúsculo).
            $bzFont = "'Trebuchet MS','Segoe UI',Tahoma,Arial,sans-serif";
            $bzNavy = '#2e3192';
            $bzPhotoCell = '';
            if ($showPhoto && !empty($d['photo']) && (Str::startsWith($d['photo'], 'data:image') || filter_var($d['photo'], FILTER_VALIDATE_URL))) {
                $bzPhotoCell = '<td valign="middle" width="52" style="width:52px;min-width:52px;vertical-align:middle;padding-right:10px">'
                    . '<span style="display:inline-block;width:48px;height:48px;border-radius:50%;'
                    . 'background-image:url(\'' . e($d['photo']) . '\');background-size:cover;background-position:center;background-repeat:no-repeat"></span></td>';
            }
            $bzNameBlock = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
                . $bzPhotoCell
                . '<td valign="middle" style="vertical-align:middle">'
                .   '<div style="font-family:' . $bzFont . ';font-size:18px;font-weight:700;color:' . $bzNavy . ';line-height:1.15">' . e($name) . '</div>'
                .   ($role !== '' ? '<div style="font-family:' . $bzFont . ';font-size:12px;color:' . $roleColor . ';line-height:1.3;margin-top:1px">' . e($role) . '</div>' : '')
                . '</td></tr></table>';
            $col2 = '<td width="235" valign="middle" style="width:235px;vertical-align:middle;padding-right:4px">'
                . $bzNameBlock
                . '<div style="margin-top:12px">' . $bzContacts . '</div>'
                . '</td>';
            // Bloco direito alinhado à ESQUERDA da coluna (encosta no texto) — antes ia p/ a borda direita.
            $col3 = '<td width="255" valign="middle" align="left" style="width:255px;vertical-align:middle">'
                . ($rightBlock !== '' ? '<img src="' . $rightBlock . '" alt="" width="250" border="0" style="width:250px;height:auto;display:block;border:0;outline:none">' : '')
                . '</td>';
            return '<table role="presentation" width="715" cellpadding="0" cellspacing="0" border="0" style="width:715px;max-width:100%;border-collapse:collapse;font-family:Arial,Helvetica,sans-serif;margin-top:6px;table-layout:fixed">'
                . '<tr>' . $col1 . $col2 . $col3 . '</tr></table>';
        }

        $topRow = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:16px"><tr>'
            . '<td valign="middle" style="vertical-align:middle">' . $banner . '</td>'
            . '<td valign="middle" align="right" style="vertical-align:middle;text-align:right;white-space:nowrap">' . $social . '</td>'
            . '</tr></table>';

        // contatos em 2 colunas: A (celular/fixo/cidade) | B (e-mail/site). Bizify não tem fixo/cidade.
        $bk = $isBizify ? 'bizify' : 'erpserv';   // conjunto de ícones (azul Bizify × roxo ERPSERV)
        $colA = '';
        if (!empty($d['phone']))  $colA .= self::contactLine('whatsapp', $iconMode, e($d['phone']), $textColor, $bk);
        if (!empty($d['phone2'])) $colA .= self::contactLine('phone', $iconMode, e($d['phone2']), $textColor, $bk);
        if (!empty($d['city']))   $colA .= self::contactLine('location', $iconMode, e($d['city']), $textColor, $bk);
        $colB = '';
        if (!empty($d['email']))  $colB .= self::contactLine('email', $iconMode, '<a href="mailto:' . e($d['email']) . '" style="color:' . $linkColor . ';text-decoration:underline">' . e($d['email']) . '</a>', $textColor, $bk);
        if (!empty($d['website'])) {
            $href = preg_match('#^https?://#i', $d['website']) ? $d['website'] : 'https://' . $d['website'];
            $colB .= self::contactLine('web', $iconMode, '<a href="' . e($href) . '" target="_blank" style="color:' . $linkColor . ';text-decoration:underline">' . e($d['website']) . '</a>', $textColor, $bk);
        }
        $grid = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td valign="top" style="vertical-align:top;padding-right:10px">' . $colA . '</td>'
            . '<td valign="top" style="vertical-align:top">' . $colB . '</td>'
            . '</tr></table>';

        $right = '<td valign="top" style="vertical-align:top">' . $topRow . $grid . '</td>';

        return '<table role="presentation" width="1" cellpadding="0" cellspacing="0" border="0" style="max-width:100%;border-collapse:collapse;font-family:Arial,Helvetica,sans-serif;margin-top:6px">'
            . '<tr>' . $left . $right . '</tr></table>';
    }
}
