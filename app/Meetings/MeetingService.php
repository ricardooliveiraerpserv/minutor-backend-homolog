<?php

namespace App\Meetings;

use App\Meetings\HelpDeskMeetingSync;
use App\Meetings\Providers\MeetingProviderFactory;
use App\Models\Meeting;
use App\Models\MeetingEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Regra de negócio da reunião — INDEPENDENTE do provider. Só conversa com a interface
 * MeetingProvider (via factory). Toda mudança registra evento na timeline.
 */
class MeetingService
{
    /**
     * Cria a reunião: persiste + participantes + chama o provider + registra 'scheduled'.
     *
     * @param array $data title, description, provider, starts_at, duration_minutes, timezone,
     *                    origin_type, origin_id, organizer_user_id, participants[]
     */
    public function create(array $data, ?User $actor): Meeting
    {
        return DB::transaction(function () use ($data, $actor) {
            // O FE manda a hora LOCAL (ex.: "2026-07-30T09:00:00") sem fuso. O app roda em UTC, então
            // parsear direto gravaria 09:00 UTC (= 06:00 BRT). Interpreta no fuso da reunião p/ gravar
            // o UTC certo — assim card, invite e Teams batem no horário que o usuário digitou.
            $tz     = $data['timezone'] ?? 'America/Sao_Paulo';
            $starts = Carbon::parse($data['starts_at'], $tz);
            $dur    = (int) ($data['duration_minutes'] ?? 30);

            $meeting = Meeting::create([
                'title'             => $data['title'],
                'description'       => $data['description'] ?? null,
                'provider'          => $data['provider'] ?? 'presencial',
                'status'            => 'scheduled',
                'starts_at'         => $starts,
                'ends_at'           => $starts->copy()->addMinutes($dur),
                'duration_minutes'  => $dur,
                'timezone'          => $tz,
                'organizer_user_id' => $data['organizer_user_id'] ?? $actor?->id,
                'origin_type'       => $data['origin_type'] ?? null,
                'origin_id'         => $data['origin_id'] ?? null,
                'created_by_id'     => $actor?->id,
            ]);

            foreach ($data['participants'] ?? [] as $p) {
                $meeting->participants()->create([
                    'user_id'             => $p['user_id'] ?? null,
                    'customer_contact_id' => $p['customer_contact_id'] ?? null,
                    'email'               => $p['email'] ?? null,
                    'name'                => $p['name'] ?? null,
                    'role'                => $p['role'] ?? 'required',
                    'is_external'         => (bool) ($p['is_external'] ?? false),
                ]);
            }

            // Provider (Fase 0: presencial/no-op; Fase 1: Teams via Graph).
            $res = MeetingProviderFactory::for($meeting->provider)->create($meeting);
            $meeting->fill([
                'external_meeting_id' => $res['external_meeting_id'] ?? null,
                'join_url'            => $res['join_url'] ?? null,
                'provider_data'       => $res['provider_data'] ?? null,
            ])->save();

            MeetingEvent::log($meeting->id, 'scheduled', ['meta' => [
                'provider' => $meeting->provider, 'starts_at' => $meeting->starts_at->toIso8601String(),
            ]]);
            if (($data['send_invites'] ?? true) && $meeting->participants()->exists()) {
                // Fase 1: envio real pelo provider/Graph. Por ora só registra o marco.
                MeetingEvent::log($meeting->id, 'invites_sent', ['meta' => ['count' => $meeting->participants()->count()]]);
            }

            // Se veio de um CHAMADO: status "Reunião agendada" + pausa SLA + interação (reusa o SLA existente).
            app(HelpDeskMeetingSync::class)->onScheduled($meeting);

            return $meeting->fresh(['participants', 'events']);
        });
    }

    public function reschedule(Meeting $meeting, string $startsAt, ?int $durationMinutes = null): Meeting
    {
        // Reagendar: mesma regra de fuso do create — interpreta a hora local no fuso da reunião.
        $starts = Carbon::parse($startsAt, $meeting->timezone ?: 'America/Sao_Paulo');
        $dur = $durationMinutes ?? $meeting->duration_minutes ?? 30;
        $meeting->update([
            'starts_at' => $starts, 'ends_at' => $starts->copy()->addMinutes($dur),
            'duration_minutes' => $dur, 'status' => 'scheduled',
        ]);
        MeetingProviderFactory::for($meeting->provider)->update($meeting);
        MeetingEvent::log($meeting->id, 'rescheduled', ['meta' => ['starts_at' => $starts->toIso8601String()]]);
        return $meeting;
    }

    public function cancel(Meeting $meeting, ?string $reason = null): Meeting
    {
        MeetingProviderFactory::for($meeting->provider)->cancel($meeting);
        $meeting->update(['status' => 'canceled']);
        MeetingEvent::log($meeting->id, 'canceled', ['meta' => ['reason' => $reason]]);
        // Se veio de um CHAMADO em "Reunião agendada": volta p/ "Em atendimento" + retoma SLA.
        app(HelpDeskMeetingSync::class)->onCanceled($meeting);
        return $meeting;
    }

    public function start(Meeting $meeting): Meeting
    {
        $meeting->update(['status' => 'live', 'started_at' => now()]);
        MeetingEvent::log($meeting->id, 'started', []);
        return $meeting;
    }

    public function end(Meeting $meeting): Meeting
    {
        $meeting->update(['status' => 'ended', 'ended_at' => now()]);
        MeetingEvent::log($meeting->id, 'ended', ['meta' => ['duration_min' => $meeting->started_at ? now()->diffInMinutes($meeting->started_at) : $meeting->duration_minutes]]);
        return $meeting;
    }
}
