<?php

namespace Tests\Unit;

use App\Meetings\Providers\TeamsMeetingProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Teste de agenda: evento simples sem Teams/convidados, apagado logo depois. */
class TeamsProbeTest extends TestCase
{
    public function test_probe_cria_evento_simples_apaga_e_le_provedores(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/calendar*' => Http::response(['allowedOnlineMeetingProviders' => ['teamsForBusiness'], 'defaultOnlineMeetingProvider' => 'teamsForBusiness'], 200),
            'graph.microsoft.com/v1.0/me/events/*' => Http::response(null, 204),
            'graph.microsoft.com/v1.0/me/events' => Http::response(['id' => 'P1'], 201),
        ]);
        $m = new \ReflectionMethod(TeamsMeetingProvider::class, 'probePlainEvent');
        $m->setAccessible(true);
        $r = $m->invoke(new TeamsMeetingProvider(), 'tok', ['start' => ['dateTime' => 'x'], 'end' => ['dateTime' => 'y']]);

        $this->assertTrue($r['calendar_ok']);
        $this->assertSame(['teamsForBusiness'], $r['teams']['allowed']);
        Http::assertSent(fn (Request $q) => $q->method() === 'POST' && !isset($q['isOnlineMeeting']) && !isset($q['attendees']));
        Http::assertSent(fn (Request $q) => $q->method() === 'DELETE' && str_ends_with($q->url(), '/me/events/P1'));
    }
}
