<?php

namespace App\Meetings\Providers;

/**
 * Resolve o adapter pelo provider da reunião. Fase 0: tudo cai no PresencialProvider (no-op).
 * Fase 1: 'teams' passa a resolver TeamsMeetingProvider (Graph). Meet/Zoom/Webex entram depois.
 */
class MeetingProviderFactory
{
    public static function for(string $provider): MeetingProvider
    {
        return match ($provider) {
            // Teams via Graph quando configurado; senão cai no no-op (reunião criada sem link).
            'teams' => TeamsMeetingProvider::enabled() ? new TeamsMeetingProvider() : new PresencialProvider(),
            // 'meet' | 'zoom' | 'webex' → adapters futuros; por ora no-op.
            default => new PresencialProvider(),
        };
    }
}
