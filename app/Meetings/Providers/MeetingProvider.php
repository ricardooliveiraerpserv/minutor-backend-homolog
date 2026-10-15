<?php

namespace App\Meetings\Providers;

use App\Models\Meeting;

/**
 * Contrato do provider de videoconferência. A regra de negócio (MeetingService) só conversa
 * com esta interface — trocar Teams por Meet/Zoom/Webex muda só o adapter, nunca o domínio.
 *
 * create()/update() devolvem: ['external_meeting_id' => ?string, 'join_url' => ?string, 'provider_data' => array]
 */
interface MeetingProvider
{
    public function create(Meeting $meeting): array;

    public function update(Meeting $meeting): array;

    public function cancel(Meeting $meeting): void;
}
