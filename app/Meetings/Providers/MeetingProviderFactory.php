<?php

namespace App\Meetings\Providers;

/**
 * Resolve o adapter pelo provider da reunião. 'teams' resolve o TeamsMeetingProvider (Graph) quando
 * ligado/configurado; senão cai no PresencialProvider (no-op → reunião criada sem link).
 */
class MeetingProviderFactory
{
    public static function for(string $provider): MeetingProvider
    {
        return match ($provider) {
            'teams' => TeamsMeetingProvider::enabled() ? new TeamsMeetingProvider() : new PresencialProvider(),
            // 'meet' | 'zoom' | 'webex' → adapters futuros; por ora no-op.
            default => new PresencialProvider(),
        };
    }
}
