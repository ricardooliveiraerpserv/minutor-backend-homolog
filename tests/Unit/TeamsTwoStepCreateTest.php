<?php

namespace Tests\Unit;

use App\Meetings\Providers\TeamsMeetingProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 502 no POST completo → cria enxuta e adiciona convidados via PATCH (que dispara os convites). */
class TeamsTwoStepCreateTest extends TestCase
{
    public function test_cria_em_duas_etapas_e_adiciona_convidados(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/events/*' => Http::response(['id' => 'E1', 'onlineMeeting' => ['joinUrl' => 'https://teams/j']], 200),
            'graph.microsoft.com/v1.0/me/events' => Http::response(['id' => 'E1', 'onlineMeeting' => ['joinUrl' => 'https://teams/j']], 201),
        ]);
        $payload = ['subject' => 'R', 'start' => [], 'end' => [], 'transactionId' => 'minutor-abc',
            'attendees' => [['emailAddress' => ['address' => 'a@x.com'], 'type' => 'required']],
            'body' => ['contentType' => 'HTML', 'content' => '<p>oi</p>']];
        $m = new \ReflectionMethod(TeamsMeetingProvider::class, 'createInTwoSteps');
        $m->setAccessible(true);
        [$ev, $extra] = $m->invoke(new TeamsMeetingProvider(), 'tok', $payload);

        $this->assertSame('https://teams/j', data_get($ev, 'onlineMeeting.joinUrl'));
        $this->assertFalse($extra['invites_failed']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && !isset($r['attendees']) && $r['isOnlineMeeting'] === true);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['attendees'][0]['emailAddress']['address'] === 'a@x.com');
    }
}
