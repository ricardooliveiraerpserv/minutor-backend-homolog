<?php

namespace App\Support;

use Spatie\Multitenancy\Models\Tenant;

/**
 * Marca por tenant para e-mails/PDFs. No tenant CONECTA remove Minutor/ERPServ e usa
 * "Conecta ERP" + logos oficiais. Grupo/ERPSERV mantém a marca original.
 * Usado nos blades: \App\Support\Brand::xxx().
 */
class Brand
{
    public static function isConecta(): bool
    {
        // CONECTA migrou para repositório/infra próprios (VPS Leandro). Nesta base
        // (ERPServ) a marca é SEMPRE ERPServ/Minutor — nenhuma mudança da Conecta
        // pode impactar o ERPServ. NÃO religar este gate nesta base.
        return false;
    }

    /** Nome legal (rodapé/copyright/alt). */
    public static function name(): string
    {
        return self::isConecta() ? 'Conecta ERP' : 'ERPServ Consultoria';
    }

    /** Nome do produto ("Acessar o X", títulos). */
    public static function appName(): string
    {
        return self::isConecta() ? 'Conecta ERP' : 'Minutor';
    }

    /** Slogan do produto. */
    public static function tagline(): string
    {
        return self::isConecta() ? 'ERP para a era digital.' : 'Controle de horas e contratos em um só lugar';
    }

    /** Logo p/ cabeçalho ESCURO (base64 inline): conecta transparente texto branco | ERPServ branco. */
    public static function darkLogoPath(): string
    {
        return public_path(self::isConecta() ? 'conecta-logo-white-transp.png' : 'logo-erpserv-white.png');
    }

    /** Logo p/ fundo CLARO (base64 inline): conecta transparente texto roxo | ERPServ colorido. */
    public static function lightLogoPath(): string
    {
        return public_path(self::isConecta() ? 'conecta-logo-light.png' : 'logo.png');
    }

    /** URL do logo p/ fundo CLARO (quando o template referencia por URL). */
    public static function lightLogoUrl(): string
    {
        $host = rtrim((string) config('app.url', 'https://app.minutor.com.br'), '/');
        return $host . '/' . (self::isConecta() ? 'conecta-logo-light.png' : 'logo.png');
    }
}
