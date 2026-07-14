<?php

namespace App\Meetings\Providers;

use App\Models\Meeting;

/**
 * Provider "presencial" (e fallback na Fase 0): não fala com API externa — só persiste.
 * Permite todo o fluxo (agendar/card/timeline) funcionar sem Teams enquanto o adapter Graph
 * (Fase 1) não está ligado.
 */
class PresencialProvider implements MeetingProvider
{
    public function create(Meeting $meeting): array
    {
        return ['external_meeting_id' => null, 'join_url' => null, 'provider_data' => []];
    }

    public function update(Meeting $meeting): array
    {
        return [];
    }

    public function cancel(Meeting $meeting): void
    {
        // nada a cancelar externamente
    }
}
