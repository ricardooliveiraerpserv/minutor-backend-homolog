<?php

namespace Tests\Unit;

use App\Services\MicrosoftCalendarService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** createEvent guarda o motivo real da Microsoft e tokenScopes lê as permissões do token. */
class MicrosoftCalendarErrorTest extends TestCase
{
    public function test_create_event_registra_erro_do_graph_e_le_scopes(): void
    {
        Http::fake(['graph.microsoft.com/*' => Http::response(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.']], 403)]);
        $this->assertNull(MicrosoftCalendarService::createEvent('tok', ['subject' => 'x']));
        $this->assertSame(403, MicrosoftCalendarService::$lastError['status']);
        $this->assertSame('ErrorAccessDenied', MicrosoftCalendarService::$lastError['code']);

        $jwt = 'h.' . rtrim(strtr(base64_encode(json_encode(['scp' => 'Calendars.Read User.Read'])), '+/', '-_'), '=') . '.s';
        $this->assertSame('Calendars.Read User.Read', MicrosoftCalendarService::tokenScopes($jwt));
    }
}
