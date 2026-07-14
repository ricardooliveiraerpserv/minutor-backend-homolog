<?php

namespace App\Meetings;

/**
 * Registry das ORIGENS de uma reunião (mesma ideia do AttachableEntitiesRegistry).
 * origin_type → [classe do model, rótulo]. "Criar de X" muda só a origem, nunca a estrutura.
 */
class MeetingOriginRegistry
{
    public const MAP = [
        'HELPDESK_TICKET' => [\App\Models\HelpDeskTicket::class, 'Chamado'],
        'PROJECT'         => [\App\Models\Project::class,        'Projeto'],
        'CUSTOMER'        => [\App\Models\Customer::class,       'Cliente'],
        'CONTRACT'        => [\App\Models\Contract::class,       'Contrato'],
        'AGENDA'          => [null,                              'Agenda'],
    ];

    public static function modelFor(?string $type): ?string
    {
        return self::MAP[$type][0] ?? null;
    }

    public static function label(?string $type): ?string
    {
        return self::MAP[$type][1] ?? null;
    }

    public static function isValid(?string $type): bool
    {
        return $type !== null && array_key_exists($type, self::MAP);
    }
}
