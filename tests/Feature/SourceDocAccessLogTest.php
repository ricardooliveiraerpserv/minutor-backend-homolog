<?php

namespace Tests\Feature;

use App\Models\SourceDoc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\TestHelpers;

/** Auditoria do código-fonte: tentativa de captura registrada e lista de acessos só p/ admin. */
class SourceDocAccessLogTest extends TestCase
{
    use RefreshDatabase, TestHelpers;

    private function doc(): SourceDoc
    {
        return SourceDoc::create([
            'customer_id' => null, 'owner' => 'org', 'repository' => 'repo', 'branch' => 'main',
            'path' => 'src/X.prw', 'filename' => 'X.prw', 'lang' => 'advpl', 'analysis_status' => 'pending',
        ]);
    }

    /** @test */
    public function tentativa_de_captura_fica_registrada_e_admin_ve_na_lista(): void
    {
        $admin = User::factory()->create(['type' => 'admin', 'email' => 'adm-acc-' . uniqid() . '@test.local']);
        $doc = $this->doc();
        $h = $this->authHeaders($admin);

        $this->postJson($this->apiUrl("/source-docs/{$doc->id}/capture-attempt"), ['trigger' => 'cmd_shift'], $h)->assertOk();
        $this->postJson($this->apiUrl("/source-docs/{$doc->id}/capture-attempt"), ['trigger' => 'qualquer'], $h)->assertStatus(422);

        $r = $this->getJson($this->apiUrl("/source-docs/{$doc->id}/access-log"), $h)->assertOk();
        $this->assertSame('capture_attempt', $r->json('data.0.event'));
        $this->assertSame('cmd_shift', $r->json('data.0.trigger'));
        $this->assertSame($admin->id, $r->json('data.0.user.id'));
        $this->assertNotEmpty($r->json('data.0.ip'));
    }

    /** @test */
    public function nao_admin_nao_ve_a_lista_de_acessos(): void
    {
        $c = User::factory()->create(['type' => 'consultor', 'email' => 'cons-acc-' . uniqid() . '@test.local']);
        $doc = $this->doc();
        $this->getJson($this->apiUrl("/source-docs/{$doc->id}/access-log"), $this->authHeaders($c))->assertStatus(403);
    }
}
