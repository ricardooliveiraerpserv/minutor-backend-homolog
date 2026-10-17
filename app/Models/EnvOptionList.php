<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnvOptionList extends Model
{
    protected $table = 'env_option_lists';

    protected $fillable = ['list_key', 'value', 'label', 'sort', 'created_by'];

    /** Chaves válidas de lista + defaults built-in (semeados sob demanda). */
    public const LISTS = ['credential_category', 'db_engine', 'vpn_provider', 'link_kind'];

    public const DEFAULTS = [
        'credential_category' => [
            ['win_admin', 'Administrador Windows'], ['sql', 'Administrador SQL'],
            ['protheus', 'Administrador Protheus'], ['fluig', 'Administrador Fluig'],
            ['totvs_license', 'TOTVS License'], ['smtp', 'SMTP'], ['ftp', 'FTP'],
            ['azure', 'Azure'], ['aws', 'AWS'], ['gcp', 'Google Cloud'],
            ['o365', 'Office 365'], ['portal', 'Portal'],
        ],
        'db_engine' => [
            ['sqlserver', 'SQL Server'], ['postgres', 'PostgreSQL'], ['oracle', 'Oracle'], ['mysql', 'MySQL'],
        ],
        'vpn_provider' => [
            ['fortinet', 'Fortinet'], ['openvpn', 'OpenVPN'], ['other', 'Outro'],
        ],
        'link_kind' => [
            ['fluig', 'Fluig'], ['tss', 'TSS'], ['portal', 'Portal TOTVS'], ['powerbi', 'Power BI'],
            ['rdp', 'RDP'], ['sharepoint', 'SharePoint'], ['azure', 'Azure'], ['aws', 'AWS'], ['other', 'Outro'],
        ],
    ];
}
