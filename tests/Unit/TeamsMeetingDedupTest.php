<?php

namespace Tests\Unit;

use App\Meetings\Providers\TeamsMeetingProvider;
use App\Models\Meeting;
use App\Services\MicrosoftHealth;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Teams: não duplica reunião após timeout, classifica a falha e registra a saúde. */
class TeamsMeetingDedupTest extends TestCase
{
    private function provider(): TeamsMeetingProvider
    {
        config(['services.meetings.teams_enabled' => true,
            'services.microsoft_calendar' => ['client_id' => 'c', 'client_secret' => 's', 'tenant' => 't', 'redirect_uri' => 'https://x/cb']]);
        return new class extends TeamsMeetingProvider {
            protected function tokenFor(Meeting $meeting): ?string { return 'h.' . rtrim(strtr(base64_encode(json_encode(['scp' => 'Calendars.ReadWrite'])), '+/', '-_'), '=') . '.s'; }
        };
    }

    private function meeting(): Meeting
    {
        $m = new Meeting(['title' => 'Reunião X', 'description' => '<p>oi</p>', 'starts_at' => '2026-11-20 13:00:00', 'ends_at' => '2026-11-20 13:30:00', 'timezone' => 'America/Sao_Paulo']);
        $m->setRelation('participantRows', collect([(object) ['email' => 'a@x.com', 'name' => 'A', 'role' => 'required']]));
        return $m;
    }

    private function existing(): array
    {
        return ['id' => 'E1', 'subject' => 'Reunião X', 'start' => ['dateTime' => '2026-11-20T13:00:00.0000000'],
            'onlineMeeting' => ['joinUrl' => 'https://teams/j'], 'attendees' => [['emailAddress' => ['address' => 'a@x.com']]]];
    }

    public function test_reaproveita_evento_existente_sem_criar_outro(): void
    {
        Cache::flush();
        Http::fake(['graph.microsoft.com/v1.0/me/calendarView*' => Http::response(['value' => [$this->existing()]], 200)]);
        $r = $this->provider()->create($this->meeting());

        $this->assertSame('https://teams/j', $r['join_url']);
        $this->assertTrue($r['provider_data']['reused']);
        Http::assertNotSent(fn (Request $q) => $q->method() === 'POST');
    }

    public function test_timeout_e_depois_encontra_na_agenda_nao_duplica(): void
    {
        Cache::flush();
        Http::fake(['graph.microsoft.com/v1.0/me/calendarView*' => Http::sequence()
                ->push(['value' => []], 200)->push(['value' => [$this->existing()]], 200),
            'graph.microsoft.com/v1.0/me/events' => Http::response(['error' => ['code' => 'UnknownError', 'message' => '']], 502)]);
        $r = $this->provider()->create($this->meeting());

        $this->assertSame('https://teams/j', $r['join_url']);
        $this->assertTrue($r['provider_data']['recovered_after_error']);
        Http::assertSent(fn (Request $q) => $q->method() === 'POST' && str_starts_with((string) $q['transactionId'], 'minutor-'));
    }

    public function test_falha_da_microsoft_classificada_sem_token_e_saude_degradada(): void
    {
        Cache::flush();
        Http::fake(['graph.microsoft.com/v1.0/me/calendarView*' => Http::response(['value' => []], 200),
            'graph.microsoft.com/v1.0/me/events' => Http::response(['error' => ['code' => 'UnknownError', 'message' => 'Bearer eyJabc.def.ghi falhou']], 502)]);
        $r = $this->provider()->create($this->meeting());
        $pd = $r['provider_data'];

        $this->assertNull($r['join_url']);
        $this->assertSame('microsoft_unavailable', $pd['kind']);
        $this->assertSame('not_created', $pd['outcome']);
        $this->assertTrue($pd['probe']['calendar_ok']);
        $this->assertFalse($pd['missing_write']);
        $this->assertStringNotContainsString('eyJ', json_encode($pd));
        $this->assertArrayNotHasKey('scopes', $pd);
        $this->assertSame('degraded', MicrosoftHealth::snapshot()['teams']['state']);
        $this->assertSame('ok', MicrosoftHealth::snapshot()['agenda']['state']);
    }

    public function test_estado_recuperado_apos_falha(): void
    {
        Cache::flush();
        MicrosoftHealth::record('agenda', false, ['status' => 0, 'code' => 'exception', 'message' => 'cURL error 28 (see https://curl.haxx.se/x)']);
        $this->assertSame('degraded', MicrosoftHealth::snapshot()['agenda']['state']);
        $this->travel(1)->seconds();
        MicrosoftHealth::record('agenda', true);
        $s = MicrosoftHealth::snapshot()['agenda'];
        $this->assertSame('recovered', $s['state']);
        $this->assertStringNotContainsString('http', $s['last_error']['message']);
    }
}
